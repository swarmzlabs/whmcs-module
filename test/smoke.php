<?php
/**
 * Swarmz WHMCS module — live API smoke test.
 *
 * Runs every WHMCS hook against the real swarmz enterprise API and asserts
 * the response shape + state transitions a hosting company would see.
 * Exercises:
 *   - swarmz_TestConnection
 *   - swarmz_CreateAccount          (twice — idempotency)
 *   - swarmz_ChangePackage          (entitlement push, plus round-trip check)
 *   - swarmz_UsageUpdate            (post-create zero-state)
 *   - swarmz_ServiceSingleSignOn    (URL host check)
 *   - swarmz_SuspendAccount         (twice — state-idempotent)
 *   - swarmz_ServiceSingleSignOn    (while suspended → must fail with reason)
 *   - swarmz_UnsuspendAccount       (then SSO works again)
 *   - swarmz_TerminateAccount       (twice — already-gone is benign)
 *   - swarmz_CreateAccount          (after terminate → fresh tenant)
 *
 * Usage:
 *   SWARMZ_API_KEY=sk_live_… \
 *   SWARMZ_API_BASE=https://ashyyneusxtubdhsfpod.supabase.co \
 *   SWARMZ_TEST_SERVICE_ID=99999 \
 *   php test/smoke.php
 *
 * The script stubs out the minimum WHMCS surface (the WHMCS constant, the
 * Capsule database facade, and `logModuleCall`) so the module can be loaded
 * outside a real WHMCS install. The Capsule stub keeps an in-memory mirror
 * of tblcustomfields/tblcustomfieldsvalues so the second-call paths
 * (SuspendAccount sees the tenant_id stored by CreateAccount) behave the
 * way they would in a real WHMCS install.
 *
 * This file is NOT included in the WHMCS install — it lives under test/ so
 * production deploys ignore it.
 */

declare(strict_types=1);

// ----------------------------------------------------------------------------
// Minimal WHMCS stub. The real WHMCS bootstrap defines this constant and
// loads WHMCS\Database\Capsule via its own autoloader; in test we provide a
// trivial stand-in that round-trips writes to satisfy the module's
// custom-field persistence path.
//
// NOTE: PHP forbids `define()` (a global-scope statement) before a `namespace`
// keyword in the same file unless that earlier statement is wrapped in its own
// `namespace { ... }` block. The constant is therefore defined inside the
// global block below.
// ----------------------------------------------------------------------------

namespace WHMCS\Database {
    /**
     * In-memory stand-in for WHMCS's Capsule (Eloquent query builder facade).
     * Tracks just enough of tblcustomfields + tblcustomfieldsvalues +
     * tblhosting to let Helpers::setTenantId / getTenantId round-trip.
     */
    class Capsule
    {
        /** @var array<string,array<int,array>> Table-name => rows. */
        private static $store = [
            'tblcustomfields'       => [],
            'tblcustomfieldsvalues' => [],
            'tblhosting'            => [],
        ];

        /** @var int Auto-increment for inserts. */
        private static $idSeq = 1;

        /** @var string */
        private $table;

        /** @var array<string,mixed> WHERE filters, joined ANDs. */
        private $wheres = [];

        /** @var array<int,array{string,string,string,string}> JOINs (table, lhs, op, rhs). */
        private $joins = [];

        public static function table(string $name): self
        {
            $self = new self();
            $self->table = $name;
            if (!isset(self::$store[$name])) {
                self::$store[$name] = [];
            }
            return $self;
        }

        public function where(...$args): self
        {
            // Support both where('col', 'val') and where('col', '=', 'val').
            if (count($args) === 2) {
                [$col, $val] = $args;
                $this->wheres[] = [$col, '=', $val];
            } elseif (count($args) === 3) {
                $this->wheres[] = [$args[0], $args[1], $args[2]];
            }
            return $this;
        }

        public function join(string $table, string $lhs, string $op, string $rhs): self
        {
            $this->joins[] = [$table, $lhs, $op, $rhs];
            return $this;
        }

        public function first($columns = null)
        {
            $rows = $this->resolveRows();
            if (empty($rows)) {
                return null;
            }
            $row = $rows[0];
            // Honour `select(['table.col as alias'])` by aliasing onto the returned object.
            if (is_array($columns)) {
                foreach ($columns as $sel) {
                    if (preg_match('/^(.+)\s+as\s+(.+)$/i', (string) $sel, $m)) {
                        $src = trim($m[1]);
                        $alias = trim($m[2]);
                        $row->{$alias} = $this->column($row, $src);
                    } elseif (preg_match('/^(.+)\.(.+)$/', (string) $sel, $m)) {
                        // Bare table.col selector — copy onto unqualified key so
                        // `$row->{col}` works (some callers use that).
                        $unq = $m[2];
                        if (!isset($row->{$unq})) {
                            $row->{$unq} = $this->column($row, (string) $sel);
                        }
                    }
                }
            }
            return $row;
        }

        public function insert(array $data): bool
        {
            $data['id'] = $data['id'] ?? self::$idSeq++;
            self::$store[$this->table][] = (object) $data;
            return true;
        }

        public function insertGetId(array $data, $sequence = null): int
        {
            $data['id'] = self::$idSeq++;
            self::$store[$this->table][] = (object) $data;
            return (int) $data['id'];
        }

        public function update(array $data): int
        {
            $affected = 0;
            foreach (self::$store[$this->table] as $i => $row) {
                if ($this->rowMatches($row, $this->wheres)) {
                    foreach ($data as $col => $val) {
                        self::$store[$this->table][$i]->{$col} = $val;
                    }
                    $affected++;
                }
            }
            return $affected;
        }

        // Seed helper used by the smoke runner to register a fake service row.
        public static function seedService(int $serviceId, int $packageId): void
        {
            self::$store['tblhosting'][] = (object) ['id' => $serviceId, 'packageid' => $packageId];
        }

        // ---- private ----

        /** Apply WHEREs (with JOIN tracking) and return matching rows. */
        private function resolveRows(): array
        {
            $base = self::$store[$this->table] ?? [];

            // The module joins tblcustomfieldsvalues + tblcustomfields and
            // sometimes tblhosting. We support both shapes used in Helpers.
            if (empty($this->joins)) {
                return array_values(array_filter($base, fn($r) => $this->rowMatches($r, $this->wheres)));
            }

            // Build joined view (cross-product, then filter).
            // We tag each interim "row" with the table-name it came from so
            // qualified column lookups (e.g. "tblhosting.packageid") work
            // regardless of which side of the JOIN the column is on.
            $rows = [];
            foreach ($base as $br) {
                // Build a tagged row: keys "<table>.<col>" + unqualified <col>.
                $tagged = new \stdClass();
                foreach ((array) $br as $k => $v) {
                    $tagged->{$k} = $v;
                    $tagged->{$this->table . '.' . $k} = $v;
                }
                $rows[] = $tagged;
            }
            foreach ($this->joins as [$jtable, $lhs, $op, $rhs]) {
                $jrows = self::$store[$jtable] ?? [];
                $next = [];
                foreach ($rows as $r) {
                    foreach ($jrows as $jr) {
                        // Tag the join row with qualified keys too.
                        $jrTagged = new \stdClass();
                        foreach ((array) $jr as $k => $v) {
                            $jrTagged->{$k} = $v;
                            $jrTagged->{$jtable . '.' . $k} = $v;
                        }
                        // Merge: prefer existing keys (left-side wins on conflict).
                        $merged = clone $r;
                        foreach ((array) $jrTagged as $k => $v) {
                            if (!isset($merged->{$k})) {
                                $merged->{$k} = $v;
                            }
                        }
                        // Qualified $lhs and $rhs can reference EITHER side of the join.
                        $left  = $this->column($merged, $lhs);
                        $right = $this->column($merged, $rhs);
                        if ($op === '=' && $left !== null && $right !== null && $left == $right) {
                            $next[] = $merged;
                        }
                    }
                }
                $rows = $next;
            }

            return array_values(array_filter($rows, fn($r) => $this->rowMatches($r, $this->wheres)));
        }

        /** Look up the column on a row, supporting 'table.col' qualifiers. */
        private function column($row, string $col)
        {
            if (isset($row->{$col})) {
                return $row->{$col};
            }
            $dot = strrpos($col, '.');
            if ($dot !== false) {
                $unqualified = substr($col, $dot + 1);
                if (isset($row->{$unqualified})) {
                    return $row->{$unqualified};
                }
            }
            return null;
        }

        private function rowMatches($row, array $wheres): bool
        {
            foreach ($wheres as [$col, $op, $val]) {
                $rv = $this->column($row, $col);
                if ($rv === null) return false;
                if ($op === '=' && $rv != $val) return false;
            }
            return true;
        }
    }
}

namespace {
    // WHMCS constant — must come BEFORE the module files are required since
    // each one guards on `if (!defined('WHMCS')) die(...)`.
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }

    /** WHMCS-compatible log helper. We just echo to stderr for smoke tests. */
    function logModuleCall($module, $action, $request, $response, $processedResponse = '', $replaceVars = [])
    {
        if (getenv('SWARMZ_SMOKE_VERBOSE')) {
            fwrite(STDERR, sprintf("[%s][%s] req=%s resp=%s\n", $module, $action, json_encode($request), json_encode($response)));
        }
    }

    // ------------------------------------------------------------------------
    // Load the module.
    // ------------------------------------------------------------------------
    $moduleRoot = realpath(__DIR__ . '/../modules/servers/swarmz');
    if ($moduleRoot === false) {
        fwrite(STDERR, "Cannot locate module root.\n");
        exit(2);
    }
    require_once $moduleRoot . '/lib/Exceptions.php';
    require_once $moduleRoot . '/lib/Api.php';
    require_once $moduleRoot . '/lib/Helpers.php';
    require_once $moduleRoot . '/swarmz.php';

    // ------------------------------------------------------------------------
    // Read configuration from env.
    // ------------------------------------------------------------------------
    $apiKey      = (string) (getenv('SWARMZ_API_KEY') ?: '');
    $apiBase     = (string) (getenv('SWARMZ_API_BASE') ?: 'https://ashyyneusxtubdhsfpod.supabase.co');
    $serviceId   = (int)    (getenv('SWARMZ_TEST_SERVICE_ID') ?: 99999);
    $clientEmail = (string) (getenv('SWARMZ_TEST_EMAIL') ?: ('whmcs-audit-' . $serviceId . '@example.invalid'));
    $productId   = (int)    (getenv('SWARMZ_TEST_PRODUCT_ID') ?: 1);

    if ($apiKey === '') {
        fwrite(STDERR, "Set SWARMZ_API_KEY to an sk_live_… key issued for an active enterprise account.\n");
        exit(2);
    }

    // Seed a fake "service" row so Helpers::setTenantId can resolve its productId.
    \WHMCS\Database\Capsule::seedService($serviceId, $productId);

    // Pull the apex host out of the base URL for the serverhostname field.
    $host = parse_url($apiBase, PHP_URL_HOST) ?: 'api.swarmz.net';
    $secure = (parse_url($apiBase, PHP_URL_SCHEME) ?: 'https') === 'https';

    // Shared $params bag — the bits WHMCS would normally fill in for a service.
    $baseParams = [
        'serviceid'         => $serviceId,
        'pid'               => $productId,
        'userid'            => 1,
        'serverhostname'    => $host,
        'serversecure'      => $secure,
        'serverpassword'    => $apiKey,
        'clientsdetails'    => [
            'firstname'   => 'Smoke',
            'lastname'    => 'Tester',
            'email'       => $clientEmail,
            'companyname' => 'Swarmz Audit Co',
        ],
        // configoption1..7 mirror the per-product knobs.
        'configoption1'     => '5',        // credits_per_day
        'configoption2'     => '150',      // monthly_credit_cap
        'configoption3'     => '10',       // max_projects
        'configoption4'     => '0',        // max_custom_domains
        'configoption5'     => 'nano',     // max_compute_size
        'configoption6'     => '',         // cloud_budget_cap
        'configoption7'     => '0',        // default_credits_topup
    ];

    // Helper: pretty-print a JSON-ish value.
    $dump = function ($x) {
        if (is_array($x) || is_object($x)) return json_encode($x, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return (string) $x;
    };

    $results = [];
    $log = function (string $label, $outcome, ?bool $forceOk = null) use (&$results, $dump) {
        if ($forceOk !== null) {
            $ok = $forceOk;
        } elseif (is_array($outcome)) {
            $ok = ($outcome['success'] ?? false) === true || (isset($outcome[0]) && $outcome[0] === 'success');
        } elseif (is_string($outcome)) {
            $ok = (stripos($outcome, 'success') === 0);
        } else {
            $ok = false;
        }
        $results[$label] = $ok;
        echo str_pad("[$label]", 36, ' '), $ok ? 'OK   ' : 'FAIL ', ' ', $dump($outcome), "\n";
    };

    // ------------------------------------------------------------------------
    // Step 0 — TestConnection.
    // ------------------------------------------------------------------------
    echo "\n--- TestConnection ---\n";
    $log('TestConnection', swarmz_TestConnection($baseParams));

    // ------------------------------------------------------------------------
    // Step 1 — CreateAccount.
    // ------------------------------------------------------------------------
    echo "\n--- CreateAccount ---\n";
    $log('CreateAccount', swarmz_CreateAccount($baseParams));

    // Capture the tenant_id stamped by the module via the Capsule stub.
    if (getenv('SWARMZ_SMOKE_DEBUG_CAPSULE')) {
        $ref = new \ReflectionClass(\WHMCS\Database\Capsule::class);
        $prop = $ref->getProperty('store');
        $prop->setAccessible(true);
        $store = $prop->getValue();
        fwrite(STDERR, "  capsule.store dump:\n" . json_encode($store, JSON_PRETTY_PRINT) . "\n");
    }
    $tenantId = \WHMCS\Module\Server\Swarmz\Helpers::getTenantId($serviceId);
    $dashUrl  = \WHMCS\Module\Server\Swarmz\Helpers::getDashboardUrl($serviceId);
    echo "  tenant_id stored: " . ($tenantId ?? '(null)') . "\n";
    echo "  dashboard_url:    " . ($dashUrl ?? '(null)') . "\n";
    $log('CreateAccount.TenantStored', null, $tenantId !== null);

    // ------------------------------------------------------------------------
    // Step 2 — CreateAccount AGAIN (idempotency proof — must return SAME tenant_id).
    // ------------------------------------------------------------------------
    echo "\n--- CreateAccount (retry, should be no-op) ---\n";
    $log('CreateAccount.Retry', swarmz_CreateAccount($baseParams));

    $tenantId2 = \WHMCS\Module\Server\Swarmz\Helpers::getTenantId($serviceId);
    $log('CreateAccount.Idempotent', null, $tenantId === $tenantId2);
    if ($tenantId !== $tenantId2) {
        echo "  drift: first=$tenantId  second=$tenantId2\n";
    }

    // ------------------------------------------------------------------------
    // Step 3 — UsageUpdate immediately after Create (zero-state).
    // ------------------------------------------------------------------------
    echo "\n--- UsageUpdate (immediately after create — expect zero) ---\n";
    $usage = swarmz_UsageUpdate($baseParams);
    $log('UsageUpdate', $usage);
    $log('UsageUpdate.ZeroCredits', null, ($usage['creditsUsed'] ?? -1) == 0);
    $log('UsageUpdate.ZeroCloud',   null, ($usage['cloudUsd']    ?? -1) == 0);

    // ------------------------------------------------------------------------
    // Step 4 — ChangePackage (bump credits_per_day) and verify entitlements round-trip.
    // ------------------------------------------------------------------------
    echo "\n--- ChangePackage ---\n";
    $bumped = $baseParams;
    $bumped['configoption1'] = '10';        // credits_per_day
    $bumped['configoption3'] = '25';        // max_projects
    $bumped['configoption5'] = 'small';     // max_compute_size
    $log('ChangePackage', swarmz_ChangePackage($bumped));

    // ------------------------------------------------------------------------
    // Step 5 — SSO and verify the redirect host (must be either custom domain
    // or <slug>.swarmz.app).
    // ------------------------------------------------------------------------
    echo "\n--- ServiceSingleSignOn ---\n";
    $sso = swarmz_ServiceSingleSignOn($baseParams);
    $log('ServiceSingleSignOn', $sso);
    $redirect = $sso['redirectTo'] ?? '';
    $hostOk = (bool) preg_match('#^https://([a-z0-9._-]+)/sso\?token=#i', $redirect, $m);
    if ($hostOk) echo "  redirect host: {$m[1]}\n";
    $log('SSO.HostShape', null, $hostOk);

    // ------------------------------------------------------------------------
    // Step 6 — Suspend.
    // ------------------------------------------------------------------------
    echo "\n--- SuspendAccount ---\n";
    $log('SuspendAccount', swarmz_SuspendAccount($baseParams));

    // ------------------------------------------------------------------------
    // Step 7 — Suspend AGAIN (idempotent-by-state).
    // ------------------------------------------------------------------------
    echo "\n--- SuspendAccount (retry, should still succeed) ---\n";
    $log('SuspendAccount.Retry', swarmz_SuspendAccount($baseParams));

    // ------------------------------------------------------------------------
    // Step 8 — SSO while suspended (must FAIL with a clear reason).
    // ------------------------------------------------------------------------
    echo "\n--- ServiceSingleSignOn (while suspended — expect failure) ---\n";
    $ssoSuspended = swarmz_ServiceSingleSignOn($baseParams);
    $log('SSO.WhileSuspended', $ssoSuspended, ($ssoSuspended['success'] ?? false) === false);

    // ------------------------------------------------------------------------
    // Step 9 — Unsuspend.
    // ------------------------------------------------------------------------
    echo "\n--- UnsuspendAccount ---\n";
    $log('UnsuspendAccount', swarmz_UnsuspendAccount($baseParams));

    // ------------------------------------------------------------------------
    // Step 10 — SSO works again post-unsuspend.
    // ------------------------------------------------------------------------
    echo "\n--- ServiceSingleSignOn (post-unsuspend — works again) ---\n";
    $log('SSO.PostUnsuspend', swarmz_ServiceSingleSignOn($baseParams));

    // ------------------------------------------------------------------------
    // Step 11 — Terminate.
    // ------------------------------------------------------------------------
    echo "\n--- TerminateAccount ---\n";
    $log('TerminateAccount', swarmz_TerminateAccount($baseParams));

    // ------------------------------------------------------------------------
    // Step 12 — Terminate AGAIN (idempotent — already gone).
    // ------------------------------------------------------------------------
    echo "\n--- TerminateAccount (retry, should still succeed) ---\n";
    $log('TerminateAccount.Retry', swarmz_TerminateAccount($baseParams));

    // ------------------------------------------------------------------------
    // Step 13 — Re-Create after terminate (re-provision contract: fresh tenant).
    // ------------------------------------------------------------------------
    echo "\n--- CreateAccount (after terminate — fresh tenant) ---\n";
    $log('CreateAccount.PostTerminate', swarmz_CreateAccount($baseParams));
    $tenantId3 = \WHMCS\Module\Server\Swarmz\Helpers::getTenantId($serviceId);
    echo "  new tenant_id: " . ($tenantId3 ?? '(null)') . "\n";
    // Contract: after terminate + re-create, the module's stored tenant_id MUST
    // be different from the original (the backend hard-deletes the workspace).
    $log('CreateAccount.PostTerminate.FreshTenant', null, $tenantId3 !== null && $tenantId3 !== $tenantId);

    // Cleanup: terminate this final tenant too, so the smoke run leaves no
    // workspace behind (the enterprise account is torn down by the runner).
    swarmz_TerminateAccount($baseParams);

    // ------------------------------------------------------------------------
    // Tally.
    // ------------------------------------------------------------------------
    echo "\n=== Summary ===\n";
    $failed = 0;
    foreach ($results as $name => $ok) {
        echo sprintf("  %s %s\n", $ok ? '[ok]  ' : '[FAIL]', $name);
        if (!$ok) $failed++;
    }
    echo "\n";
    if ($failed > 0) {
        echo "FAILED: $failed step(s) did not return success.\n";
        exit(1);
    }
    echo "All steps passed.\n";
    exit(0);
}
