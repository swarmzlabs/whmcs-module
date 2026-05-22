<?php
/**
 * Swarmz WHMCS module — live API smoke test.
 *
 * Runs each WHMCS hook against the real swarmz enterprise API. Exercises:
 *   - swarmz_TestConnection
 *   - swarmz_CreateAccount  (twice — verifies idempotency)
 *   - swarmz_ChangePackage
 *   - swarmz_UsageUpdate
 *   - swarmz_ServiceSingleSignOn
 *   - swarmz_SuspendAccount
 *   - swarmz_UnsuspendAccount
 *   - swarmz_TerminateAccount
 *
 * Usage:
 *   SWARMZ_API_KEY=sk_live_… \
 *   SWARMZ_API_BASE=https://ashyyneusxtubdhsfpod.supabase.co \
 *   SWARMZ_TEST_SERVICE_ID=99999 \
 *   php test/smoke.php
 *
 * The script stubs out the minimum WHMCS surface (the WHMCS constant, the
 * Capsule database facade, and `logModuleCall`) so the module can be loaded
 * outside a real WHMCS install. Capsule writes are silent no-ops in the stub
 * (we never persist anything during a smoke test).
 *
 * This file is NOT included in the WHMCS install — it lives under test/ so
 * production deploys ignore it.
 */

declare(strict_types=1);

// ----------------------------------------------------------------------------
// Minimal WHMCS stub. The real WHMCS bootstrap defines this constant and
// loads WHMCS\Database\Capsule via its own autoloader; in test we provide a
// trivial stand-in.
// ----------------------------------------------------------------------------
define('WHMCS', true);

namespace WHMCS\Database {
    /**
     * Stub for the WHMCS database facade. All builder calls return $this so
     * chained queries don't NPE; terminal queries return null/false/[] so the
     * module's `try/catch` blocks treat the result as "no row" and skip the
     * persistence path.
     */
    class Capsule
    {
        public static function table(string $name): self
        {
            return new self();
        }
        public function where(...$args): self { return $this; }
        public function join(...$args): self { return $this; }
        public function first($columns = null) { return null; }
        public function insert(array $data): bool { return true; }
        public function insertGetId(array $data, $sequence = null): int { return 0; }
        public function update(array $data): int { return 0; }
    }
}

namespace {
    /** WHMCS-compatible log helper. We just echo to stderr for smoke tests. */
    function logModuleCall($module, $action, $request, $response, $processedResponse = '', $replaceVars = [])
    {
        // Don't spam stdout — comment out the next line if you want full traces.
        // fwrite(STDERR, sprintf("[%s][%s] req=%s resp=%s\n", $module, $action, json_encode($request), json_encode($response)));
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

    if ($apiKey === '') {
        fwrite(STDERR, "Set SWARMZ_API_KEY to an sk_live_… key issued for an active enterprise account.\n");
        exit(2);
    }

    // Pull the apex host out of the base URL for the serverhostname field.
    $host = parse_url($apiBase, PHP_URL_HOST) ?: 'api.swarmz.net';
    $secure = (parse_url($apiBase, PHP_URL_SCHEME) ?: 'https') === 'https';

    // Shared $params bag — the bits WHMCS would normally fill in for a service.
    $baseParams = [
        'serviceid'         => $serviceId,
        'pid'               => 1,
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
        // Skip the auto-create custom-fields path during smoke tests; the
        // Capsule stub above silently no-ops anyway.
    ];

    // Helper: pretty-print a JSON-ish value.
    $dump = function ($x) {
        if (is_array($x) || is_object($x)) return json_encode($x, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return (string) $x;
    };

    $results = [];
    $log = function (string $label, $outcome) use (&$results, $dump) {
        $ok = false;
        if (is_array($outcome)) {
            $ok = ($outcome['success'] ?? false) === true || (isset($outcome[0]) && $outcome[0] === 'success');
        } elseif (is_string($outcome)) {
            $ok = (stripos($outcome, 'success') === 0);
        }
        $results[$label] = $ok;
        echo str_pad("[$label]", 28, ' '), $ok ? 'OK   ' : 'FAIL ', ' ', $dump($outcome), "\n";
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

    // ------------------------------------------------------------------------
    // Step 2 — CreateAccount AGAIN (idempotency proof).
    // ------------------------------------------------------------------------
    echo "\n--- CreateAccount (retry, should be no-op) ---\n";
    $log('CreateAccount.Retry', swarmz_CreateAccount($baseParams));

    // ------------------------------------------------------------------------
    // Step 3 — ChangePackage (bump credits_per_day).
    // ------------------------------------------------------------------------
    echo "\n--- ChangePackage ---\n";
    $bumped = $baseParams;
    $bumped['configoption1'] = '10';
    $bumped['configoption3'] = '25';
    $log('ChangePackage', swarmz_ChangePackage($bumped));

    // ------------------------------------------------------------------------
    // Step 4 — UsageUpdate (may return note=metrics_unavailable on the staging
    // server; that's still a success outcome).
    // ------------------------------------------------------------------------
    echo "\n--- UsageUpdate ---\n";
    $log('UsageUpdate', swarmz_UsageUpdate($baseParams));

    // ------------------------------------------------------------------------
    // Step 5 — SSO.
    // ------------------------------------------------------------------------
    echo "\n--- ServiceSingleSignOn ---\n";
    $log('ServiceSingleSignOn', swarmz_ServiceSingleSignOn($baseParams));

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
    // Step 8 — Unsuspend.
    // ------------------------------------------------------------------------
    echo "\n--- UnsuspendAccount ---\n";
    $log('UnsuspendAccount', swarmz_UnsuspendAccount($baseParams));

    // ------------------------------------------------------------------------
    // Step 9 — Terminate.
    // ------------------------------------------------------------------------
    echo "\n--- TerminateAccount ---\n";
    $log('TerminateAccount', swarmz_TerminateAccount($baseParams));

    // ------------------------------------------------------------------------
    // Step 10 — Terminate AGAIN (idempotent — already gone).
    // ------------------------------------------------------------------------
    echo "\n--- TerminateAccount (retry, should still succeed) ---\n";
    $log('TerminateAccount.Retry', swarmz_TerminateAccount($baseParams));

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
