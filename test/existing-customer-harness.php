<?php
/**
 * Swarmz WHMCS module — "Existing customers keep their prompt" (v1.26.0)
 * regression test.
 *
 * Drives the REAL ExistingCustomer resolver against the REAL PromptBox,
 * ExpressSignup and Helpers classes. Only the three boundaries the resolver
 * takes via $ctx are faked — the platform-sso mint, the platform-usage read
 * and WHMCS's localAPI() — through scripted, recording callables. The
 * policy selection, launch-recency ordering, capacity filter, 409 fallback,
 * starter-product order sequence, intent consumption and the post-checkout
 * auto-open all run production code against an in-memory Capsule stub
 * (test/express-harness.php's, extended to strip "table as alias").
 *
 * Usage:  php test/existing-customer-harness.php
 */

declare(strict_types=1);

namespace WHMCS\Database {

    class Capsule
    {
        /** @var array<string,array<int,array<string,mixed>>> table => rows */
        public static $store = [];

        /** @var array<string,bool> tables ensureSchema()/hasTable() knows about */
        public static $tables = [];

        /** @var array<string,array<string,bool>> table => declared columns */
        public static $columns = [];

        /** @var array<string,int> */
        private static $autoInc = [];

        /** @var callable|null fired as ($table, $data) after a matching update() */
        public static $onUpdate = null;

        public static function reset(): void
        {
            self::$store = [];
            self::$tables = [];
            self::$columns = [];
            self::$autoInc = [];
            self::$onUpdate = null;
        }

        /** "tblhosting as h" → "tblhosting" (aliases are not modelled). */
        public static function baseName(string $name): string
        {
            $parts = preg_split('/\s+as\s+/i', trim($name));
            return is_array($parts) ? $parts[0] : $name;
        }

        public static function table(string $name): FakeQuery
        {
            $name = self::baseName($name);
            if (!isset(self::$store[$name])) {
                self::$store[$name] = [];
            }
            return new FakeQuery($name);
        }

        public static function schema(): FakeSchema
        {
            return new FakeSchema();
        }

        public static function nextId(string $table): int
        {
            $id = (self::$autoInc[$table] ?? 0) + 1;
            self::$autoInc[$table] = $id;
            return $id;
        }
    }

    class FakeSchema
    {
        public function hasTable(string $name): bool
        {
            return !empty(Capsule::$tables[$name]) || !empty(Capsule::$store[$name]);
        }

        public function create(string $name, $callback): void
        {
            Capsule::$tables[$name] = true;
            if (!isset(Capsule::$store[$name])) {
                Capsule::$store[$name] = [];
            }
            $this->runBlueprint($name, $callback);
        }

        public function table(string $name, $callback): void
        {
            $this->runBlueprint($name, $callback);
        }

        public function hasColumn(string $name, string $column): bool
        {
            return !empty(Capsule::$columns[$name][$column]);
        }

        private function runBlueprint(string $name, $callback): void
        {
            $bp = new FakeBlueprint();
            if (is_callable($callback)) {
                $callback($bp);
            }
            if (!isset(Capsule::$columns[$name])) {
                Capsule::$columns[$name] = [];
            }
            Capsule::$columns[$name] = array_merge(Capsule::$columns[$name], $bp->columns);
        }
    }

    class FakeBlueprint
    {
        /** @var array<string,bool> */
        public $columns = [];

        public function __call($name, $args)
        {
            if (isset($args[0]) && is_string($args[0])) {
                $this->columns[$args[0]] = true;
            }
            return $this;
        }
    }

    class FakeQuery
    {
        private $table;
        private $wheres = [];
        private $joins = [];
        private $orderBy = null;
        private $limitN = null;

        public function __construct(string $table)
        {
            $this->table = $table;
        }

        private static function unqualify(string $col): string
        {
            $dot = strrpos($col, '.');
            return $dot === false ? $col : substr($col, $dot + 1);
        }

        public function where(...$args): self
        {
            if (count($args) === 2) {
                $this->wheres[] = [self::unqualify((string) $args[0]), '=', $args[1]];
            } elseif (count($args) === 3) {
                $this->wheres[] = [self::unqualify((string) $args[0]), (string) $args[1], $args[2]];
            }
            return $this;
        }

        public function whereIn(string $col, array $vals): self
        {
            $this->wheres[] = [self::unqualify($col), 'in', $vals];
            return $this;
        }

        public function whereNull(string $col): self
        {
            $this->wheres[] = [self::unqualify($col), 'null', null];
            return $this;
        }

        public function whereNotNull(string $col): self
        {
            $this->wheres[] = [self::unqualify($col), 'notnull', null];
            return $this;
        }

        public function join(string $table, $lhs, $op = '=', ?string $rhs = null): self
        {
            $this->joins[] = [Capsule::baseName($table), (string) $lhs, (string) $op, $rhs];
            return $this;
        }

        public function leftJoin(string $table, $lhs, $op = '=', ?string $rhs = null): self
        {
            return $this->join($table, $lhs, $op, $rhs);
        }

        public function orderBy(string $col, string $dir = 'asc'): self
        {
            $this->orderBy = [self::unqualify($col), strtolower($dir)];
            return $this;
        }

        public function limit(int $n): self
        {
            $this->limitN = $n;
            return $this;
        }

        private function rowMatches(array $row): bool
        {
            foreach ($this->wheres as [$col, $op, $val]) {
                $rv = $row[$col] ?? null;
                switch ($op) {
                    case '=':
                        if ($rv != $val) {
                            return false;
                        }
                        break;
                    case '!=':
                        if ($rv == $val) {
                            return false;
                        }
                        break;
                    case '>=':
                        if (!($rv >= $val)) {
                            return false;
                        }
                        break;
                    case '<=':
                        if (!($rv <= $val)) {
                            return false;
                        }
                        break;
                    case '<':
                        if (!($rv < $val)) {
                            return false;
                        }
                        break;
                    case '>':
                        if (!($rv > $val)) {
                            return false;
                        }
                        break;
                    case 'in':
                        if (!in_array($rv, $val)) {
                            return false;
                        }
                        break;
                    case 'null':
                        if ($rv !== null) {
                            return false;
                        }
                        break;
                    case 'notnull':
                        if ($rv === null) {
                            return false;
                        }
                        break;
                    default:
                        return false;
                }
            }
            return true;
        }

        private function resolve(): array
        {
            $rows = Capsule::$store[$this->table] ?? [];
            foreach ($this->joins as [$jtable, $lhs, $op, $rhs]) {
                $jrows = Capsule::$store[$jtable] ?? [];
                $next = [];
                foreach ($rows as $r) {
                    foreach ($jrows as $jr) {
                        $left = $r[self::unqualify($lhs)] ?? null;
                        $right = $jr[self::unqualify((string) $rhs)] ?? null;
                        if ($op === '=' && $left !== null && $left == $right) {
                            $next[] = array_merge($jr, $r);
                        }
                    }
                }
                $rows = $next;
            }
            $rows = array_values(array_filter($rows, [$this, 'rowMatches']));
            if ($this->orderBy !== null) {
                [$col, $dir] = $this->orderBy;
                usort($rows, static function ($a, $b) use ($col, $dir) {
                    $cmp = ($a[$col] ?? null) <=> ($b[$col] ?? null);
                    return $dir === 'desc' ? -$cmp : $cmp;
                });
            }
            if ($this->limitN !== null) {
                $rows = array_slice($rows, 0, $this->limitN);
            }
            return $rows;
        }

        public function first($columns = null)
        {
            $rows = $this->resolve();
            return empty($rows) ? null : (object) $rows[0];
        }

        public function get($columns = null): array
        {
            return array_map(static function ($r) {
                return (object) $r;
            }, $this->resolve());
        }

        public function count(): int
        {
            return count($this->resolve());
        }

        public function exists(): bool
        {
            return count($this->resolve()) > 0;
        }

        public function insert(array $data): bool
        {
            if (!array_key_exists('id', $data)) {
                $data['id'] = Capsule::nextId($this->table);
            }
            Capsule::$store[$this->table][] = $data;
            return true;
        }

        public function insertGetId(array $data, $sequence = null): int
        {
            $id = Capsule::nextId($this->table);
            $data['id'] = $id;
            Capsule::$store[$this->table][] = $data;
            return $id;
        }

        public function update(array $data): int
        {
            $n = 0;
            foreach (Capsule::$store[$this->table] as $i => $row) {
                if ($this->rowMatches($row)) {
                    Capsule::$store[$this->table][$i] = array_merge($row, $data);
                    $n++;
                }
            }
            if ($n > 0 && Capsule::$onUpdate !== null) {
                (Capsule::$onUpdate)($this->table, $data);
            }
            return $n;
        }

        public function delete(): int
        {
            $kept = [];
            $n = 0;
            foreach (Capsule::$store[$this->table] as $row) {
                if ($this->rowMatches($row)) {
                    $n++;
                } else {
                    $kept[] = $row;
                }
            }
            Capsule::$store[$this->table] = $kept;
            return $n;
        }

        public function updateOrInsert(array $attrs, array $values = []): bool
        {
            foreach (Capsule::$store[$this->table] as $i => $row) {
                $match = true;
                foreach ($attrs as $k => $v) {
                    if (($row[$k] ?? null) != $v) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    Capsule::$store[$this->table][$i] = array_merge($row, $values);
                    return true;
                }
            }
            $row = array_merge($attrs, $values);
            if (!array_key_exists('id', $row)) {
                $row['id'] = Capsule::nextId($this->table);
            }
            Capsule::$store[$this->table][] = $row;
            return true;
        }
    }
}

namespace {

    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }

    $GLOBALS['__logLines'] = [];

    function logModuleCall($module, $action, $request, $response, $processedResponse = '', $replaceVars = [])
    {
        $GLOBALS['__logLines'][] = ['module' => $module, 'action' => $action, 'request' => $request, 'response' => $response];
    }

    $root = realpath(__DIR__ . '/..');
    require_once $root . '/modules/servers/swarmz/lib/Exceptions.php';
    require_once $root . '/modules/servers/swarmz/lib/Api.php';
    require_once $root . '/modules/servers/swarmz/lib/Helpers.php';
    require_once $root . '/modules/addons/swarmz/lib/PromptBox.php';
    require_once $root . '/modules/addons/swarmz/lib/ExpressSignup.php';
    require_once $root . '/modules/addons/swarmz/lib/ExistingCustomer.php';

    use WHMCS\Database\Capsule;
    use WHMCS\Module\Addon\Swarmz\ExistingCustomer;
    use WHMCS\Module\Addon\Swarmz\ExpressSignup;
    use WHMCS\Module\Addon\Swarmz\PromptBox;
    use WHMCS\Module\Server\Swarmz\Helpers;

    $failed = 0;
    $check = function (string $label, bool $ok, string $detail = '') use (&$failed) {
        echo str_pad("[$label]", 62, ' '), $ok ? "OK\n" : ('FAIL ' . $detail . "\n");
        if (!$ok) {
            $failed++;
        }
    };

    // -------------------------------------------------------------------
    // Fixtures.
    // -------------------------------------------------------------------
    const CLIENT = 501;
    const OTHER_CLIENT = 502;
    const PID_MONTHLY = 12;     // recurring, priced → a PAID starter
    const PID_FREE = 13;        // paytype free → a FREE starter
    const PID_ZERO_PRICED = 14; // recurring but 0.00/month, no setup fee → free too
    const PID_NOT_SWARMZ = 99;
    const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    const PROMPT = 'Build me a bakery landing page';
    const SYSTEM_URL = 'https://shop.example.invalid';

    /** Fresh baseline: settings, products, gateway, currency — no services yet. */
    function seed(array $settings = []): void
    {
        Capsule::reset();
        Capsule::$tables[PromptBox::TABLE] = true;
        Capsule::$store['tblconfiguration'] = [
            ['setting' => 'SystemURL', 'value' => SYSTEM_URL],
        ];
        Capsule::$store['tbladdonmodules'] = [
            ['module' => 'swarmz', 'setting' => 'API Key', 'value' => 'sk_live_test'],
            ['module' => 'swarmz', 'setting' => 'API Base URL', 'value' => 'https://api.example.invalid'],
            ['module' => 'swarmz', 'setting' => 'Existing Customer Workspace', 'value' => $settings['policy'] ?? 'recent'],
            ['module' => 'swarmz', 'setting' => 'Starter Product', 'value' => $settings['starter'] ?? ''],
        ];
        if (array_key_exists('open_after_checkout', $settings)) {
            Capsule::$store['tbladdonmodules'][] = ['module' => 'swarmz', 'setting' => 'Open Editor After Checkout', 'value' => $settings['open_after_checkout']];
        }
        Capsule::$store['tblproducts'] = [
            ['id' => PID_MONTHLY, 'name' => 'Pro', 'servertype' => 'swarmz', 'paytype' => 'recurring'],
            ['id' => PID_FREE, 'name' => 'Free Starter', 'servertype' => 'swarmz', 'paytype' => 'free'],
            ['id' => PID_ZERO_PRICED, 'name' => 'Zero Monthly', 'servertype' => 'swarmz', 'paytype' => 'recurring'],
            ['id' => PID_NOT_SWARMZ, 'name' => 'cPanel', 'servertype' => 'cpanel', 'paytype' => 'recurring'],
        ];
        Capsule::$store['tblpricing'] = [
            ['type' => 'product', 'relid' => PID_MONTHLY, 'currency' => 1, 'monthly' => '9.00', 'msetupfee' => '0.00'],
            ['type' => 'product', 'relid' => PID_ZERO_PRICED, 'currency' => 1, 'monthly' => '0.00', 'msetupfee' => '0.00'],
        ];
        Capsule::$store['tblclients'] = [
            ['id' => CLIENT, 'currency' => 1],
            ['id' => OTHER_CLIENT, 'currency' => 1],
        ];
        Capsule::$store['tblcurrencies'] = [['id' => 1, 'default' => 1]];
        Capsule::$store['tblpaymentgateways'] = [
            ['gateway' => 'banktransfer', 'setting' => 'name', 'value' => 'Bank Transfer', 'order' => 1],
        ];
        Capsule::$store['tblhosting'] = [];
        Capsule::$store['tblcustomfields'] = [['id' => 1, 'type' => 'product', 'fieldname' => 'Swarmz Tenant ID']];
        Capsule::$store['tblcustomfieldsvalues'] = [];
        Capsule::$store[PromptBox::TABLE] = [];
    }

    function seedService(int $id, int $clientId, int $pid, string $status, string $regdate, ?string $tenant, string $domain = ''): void
    {
        Capsule::$store['tblhosting'][] = [
            'id' => $id, 'userid' => $clientId, 'packageid' => $pid, 'domainstatus' => $status,
            'regdate' => $regdate, 'domain' => $domain, 'orderid' => 0,
        ];
        if ($tenant !== null) {
            Capsule::$store['tblcustomfieldsvalues'][] = ['fieldid' => 1, 'relid' => $id, 'value' => $tenant];
        }
    }

    function seedLaunch(int $serviceId, string $at): void
    {
        Capsule::$tables[Helpers::LAUNCHES_TABLE] = true;
        Capsule::$store[Helpers::LAUNCHES_TABLE][] = ['serviceid' => $serviceId, 'last_launch_at' => $at];
    }

    function seedIntent(string $token = TOKEN, string $prompt = PROMPT, int $pid = PID_MONTHLY, ?int $serviceId = null, ?string $usedAt = null): void
    {
        Capsule::$store[PromptBox::TABLE][] = [
            'id' => Capsule::nextId(PromptBox::TABLE),
            'token' => $token, 'prompt' => $prompt, 'pid' => $pid, 'service_id' => $serviceId,
            'order_id' => null, 'ip' => '203.0.113.7', 'created_at' => date('Y-m-d H:i:s'),
            'bound_at' => $serviceId !== null ? date('Y-m-d H:i:s') : null, 'used_at' => $usedAt,
        ];
    }

    function intent(string $token = TOKEN): ?array
    {
        foreach (Capsule::$store[PromptBox::TABLE] ?? [] as $row) {
            if (($row['token'] ?? '') === $token) {
                return $row;
            }
        }
        return null;
    }

    function launchOf(int $serviceId): ?string
    {
        foreach (Capsule::$store[Helpers::LAUNCHES_TABLE] ?? [] as $row) {
            if ((int) ($row['serviceid'] ?? 0) === $serviceId) {
                return (string) $row['last_launch_at'];
            }
        }
        return null;
    }

    function lastLogin(): ?array
    {
        $rows = Capsule::$store[ExistingCustomer::LOGINS_TABLE] ?? [];
        return empty($rows) ? null : $rows[count($rows) - 1];
    }

    /** Three usable workspaces for CLIENT, each with distinct regdate + launch history. */
    function seedThreeWorkspaces(): void
    {
        seedService(701, CLIENT, PID_MONTHLY, 'Active', '2026-01-01', 'tenant-701', 'first.example');
        seedService(702, CLIENT, PID_MONTHLY, 'Active', '2026-03-01', 'tenant-702', 'second.example');
        seedService(703, CLIENT, PID_FREE, 'Active', '2026-05-01', 'tenant-703');
        seedLaunch(701, '2026-09-01 10:00:00');
        seedLaunch(702, '2026-09-15 10:00:00'); // most recently opened
        // 703 never launched
    }

    class Recorder
    {
        public $sso = [];     // [{sid, tenant, prompt}]
        public $usage = [];   // [sid]
        public $calls = [];   // localApi [{action, params}]
        public $events = [];  // ordered event names
    }

    /**
     * @param array $ssoScript   sid => response array (default: ok + redirect)
     * @param array $usageScript sid => ?float credits available (array_key_exists → returned, else null)
     * @param array $apiScript   action => response array|callable
     */
    function makeCtx(Recorder $rec, array $ssoScript = [], array $usageScript = [], array $apiScript = []): array
    {
        Capsule::$onUpdate = function (string $table, array $data) use ($rec) {
            if ($table !== PromptBox::TABLE) {
                return;
            }
            if (array_key_exists('used_at', $data)) {
                $rec->events[] = 'consume';
            } elseif (array_key_exists('service_id', $data)) {
                $rec->events[] = 'bind';
            }
        };
        return [
            'sso' => function (int $sid, string $tenant, ?string $prompt) use ($rec, $ssoScript): array {
                $rec->sso[] = ['sid' => $sid, 'tenant' => $tenant, 'prompt' => $prompt];
                $rec->events[] = 'sso:' . $sid;
                if (isset($ssoScript[$sid])) {
                    return $ssoScript[$sid];
                }
                return ['ok' => true, 'redirect' => 'https://app.example.invalid/sso/' . $sid];
            },
            'usage' => function (int $sid, string $tenant) use ($rec, $usageScript): ?float {
                $rec->usage[] = $sid;
                $rec->events[] = 'usage:' . $sid;
                return array_key_exists($sid, $usageScript) ? $usageScript[$sid] : null;
            },
            'localApi' => function (string $action, array $params) use ($rec, $apiScript): array {
                $rec->calls[] = ['action' => $action, 'params' => $params];
                $rec->events[] = 'localApi:' . $action;
                if (isset($apiScript[$action])) {
                    $resp = $apiScript[$action];
                    return is_callable($resp) ? $resp($params) : $resp;
                }
                return ['result' => 'success'];
            },
        ];
    }

    function callOf(Recorder $rec, string $action): ?array
    {
        foreach ($rec->calls as $c) {
            if ($c['action'] === $action) {
                return $c['params'];
            }
        }
        return null;
    }

    // ===================================================================
    // 0. Settings readers.
    // ===================================================================
    echo "--- settings readers ---\n";
    seed();
    $check('policy.default', Helpers::existingCustomerPolicy() === 'recent');
    seed(['policy' => 'bogus']);
    $check('policy.unknownFallsBackToRecent', Helpers::existingCustomerPolicy() === 'recent');
    seed(['policy' => 'ASK']);
    $check('policy.caseInsensitive', Helpers::existingCustomerPolicy() === 'ask');
    seed();
    $check('starter.emptyIsZero', Helpers::starterProductId() === 0);
    seed(['starter' => 'abc']);
    $check('starter.nonNumericIsZero', Helpers::starterProductId() === 0);
    seed(['starter' => (string) PID_FREE]);
    $check('starter.numeric', Helpers::starterProductId() === PID_FREE);
    seed();
    $check('openAfterCheckout.defaultOn', Helpers::openEditorAfterCheckout() === true);
    seed(['open_after_checkout' => 'off']);
    $check('openAfterCheckout.off', Helpers::openEditorAfterCheckout() === false);
    seed(['open_after_checkout' => 'on']);
    $check('openAfterCheckout.on', Helpers::openEditorAfterCheckout() === true);

    $loginUrl = PromptBox::loginUrl(TOKEN);
    $check('loginUrl.shape', $loginUrl === SYSTEM_URL . '/index.php?rp=/login&swzp=' . TOKEN . '&goto=' . rawurlencode('clientarea.php?swzp=' . TOKEN), $loginUrl);
    $check('cartUrl.withToken', PromptBox::cartUrl(PID_FREE, TOKEN) === SYSTEM_URL . '/cart.php?a=add&pid=' . PID_FREE . '&swzp=' . TOKEN);
    $check('cartUrl.withoutToken', PromptBox::cartUrl(PID_FREE, '') === SYSTEM_URL . '/cart.php?a=add&pid=' . PID_FREE);

    // ===================================================================
    // 1. Schema: both new tables created, idempotently.
    // ===================================================================
    echo "\n--- schema ---\n";
    seed();
    ExistingCustomer::ensureSchema();
    $check('schema.loginsTable', Capsule::schema()->hasTable(ExistingCustomer::LOGINS_TABLE));
    $check('schema.launchesTable', Capsule::schema()->hasTable(Helpers::LAUNCHES_TABLE));
    $check('schema.launchesColumns', Capsule::schema()->hasColumn(Helpers::LAUNCHES_TABLE, 'serviceid') && Capsule::schema()->hasColumn(Helpers::LAUNCHES_TABLE, 'last_launch_at'));
    ExistingCustomer::ensureSchema(); // second run must be a no-op
    $check('schema.idempotent', true);
    Helpers::recordLaunch(5);
    Helpers::recordLaunch(5);
    $check('recordLaunch.upsertsOneRow', count(Capsule::$store[Helpers::LAUNCHES_TABLE]) === 1 && launchOf(5) !== null);
    $check('lastLaunchMap.readsBack', Helpers::lastLaunchMap([5, 6]) === [5 => launchOf(5)]);

    // ===================================================================
    // 2. Eligibility: Active + swarmz product + tenant id, this client only.
    // ===================================================================
    echo "\n--- eligibility ---\n";
    seed();
    seedService(601, CLIENT, PID_MONTHLY, 'Active', '2026-01-01', 'tenant-601');
    seedService(602, CLIENT, PID_MONTHLY, 'Suspended', '2026-01-02', 'tenant-602');
    seedService(603, CLIENT, PID_MONTHLY, 'Terminated', '2026-01-03', 'tenant-603');
    seedService(604, CLIENT, PID_MONTHLY, 'Pending', '2026-01-04', 'tenant-604');
    seedService(605, CLIENT, PID_MONTHLY, 'Active', '2026-01-05', null);          // not provisioned
    seedService(606, CLIENT, PID_NOT_SWARMZ, 'Active', '2026-01-06', 'tenant-606'); // not ours
    seedService(607, OTHER_CLIENT, PID_MONTHLY, 'Active', '2026-01-07', 'tenant-607'); // someone else's
    seedService(608, CLIENT, PID_MONTHLY, 'Cancelled', '2026-01-08', 'tenant-608');
    $elig = ExistingCustomer::eligibleServices(CLIENT);
    $check('eligible.onlyActiveProvisionedSwarmzOwn', count($elig) === 1 && (int) $elig[0]['id'] === 601, json_encode(array_column($elig, 'id')));
    $check('eligible.rowShape', ($elig[0]['product'] ?? '') === 'Pro' && ($elig[0]['tenant_id'] ?? '') === 'tenant-601' && ($elig[0]['regdate'] ?? '') === '2026-01-01');

    // ===================================================================
    // 3. Policy ordering.
    // ===================================================================
    echo "\n--- policy ordering ---\n";
    seed();
    seedThreeWorkspaces();
    $cands = ExistingCustomer::eligibleServices(CLIENT);
    $ids = fn(array $list) => array_map(fn($s) => (int) $s['id'], $list);
    $check('order.recent', $ids(ExistingCustomer::orderByPolicy($cands, 'recent')) === [702, 701, 703], json_encode($ids(ExistingCustomer::orderByPolicy($cands, 'recent'))));
    $check('order.newest', $ids(ExistingCustomer::orderByPolicy($cands, 'newest')) === [703, 702, 701], json_encode($ids(ExistingCustomer::orderByPolicy($cands, 'newest'))));
    $check('order.oldest', $ids(ExistingCustomer::orderByPolicy($cands, 'oldest')) === [701, 702, 703], json_encode($ids(ExistingCustomer::orderByPolicy($cands, 'oldest'))));
    $check('order.askUsesRecent', $ids(ExistingCustomer::orderByPolicy($cands, 'ask')) === [702, 701, 703]);

    // No launches at all → recent degrades to newest (never-launched tie → newest regdate).
    seed();
    seedService(701, CLIENT, PID_MONTHLY, 'Active', '2026-01-01', 'tenant-701');
    seedService(702, CLIENT, PID_MONTHLY, 'Active', '2026-03-01', 'tenant-702');
    $check('order.recentWithoutLaunchesIsNewest', $ids(ExistingCustomer::orderByPolicy(ExistingCustomer::eligibleServices(CLIENT), 'recent')) === [702, 701]);
    // Same regdate → higher id first.
    seed();
    seedService(711, CLIENT, PID_MONTHLY, 'Active', '2026-02-01', 'tenant-711');
    seedService(712, CLIENT, PID_MONTHLY, 'Active', '2026-02-01', 'tenant-712');
    $check('order.regdateTieHigherIdFirst', $ids(ExistingCustomer::orderByPolicy(ExistingCustomer::eligibleServices(CLIENT), 'newest')) === [712, 711]);

    // ===================================================================
    // 4. recent policy end to end: SSO into the last-launched workspace WITH
    //    the prompt; intent consumed; launch + diagnostics recorded.
    // ===================================================================
    echo "\n--- recent: end to end ---\n";
    seed(['policy' => 'recent']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec));
    $check('recent.redirect', ($r['action'] ?? '') === 'redirect' && ($r['url'] ?? '') === 'https://app.example.invalid/sso/702', var_export($r, true));
    $check('recent.clearsToken', !empty($r['clear_token']));
    $check('recent.outcome', ($r['outcome'] ?? '') === 'opened' && ($r['service_id'] ?? 0) === 702);
    $check('recent.ssoOnce', count($rec->sso) === 1 && $rec->sso[0]['sid'] === 702 && $rec->sso[0]['tenant'] === 'tenant-702');
    $check('recent.ssoCarriesPrompt', ($rec->sso[0]['prompt'] ?? null) === PROMPT);
    $check('recent.usageCheckedForAllThree', $rec->usage === [701, 702, 703] || count($rec->usage) === 3, json_encode($rec->usage));
    $it = intent();
    $check('recent.intentConsumed', $it !== null && !empty($it['used_at']) && (int) $it['service_id'] === 702 && !empty($it['bound_at']), var_export($it, true));
    $check('recent.launchRecorded', launchOf(702) !== null && launchOf(702) > '2026-09-15 10:00:00');
    $login = lastLogin();
    $check('recent.diagRow', $login !== null && $login['outcome'] === 'opened' && (int) $login['client_id'] === CLIENT && (int) $login['service_id'] === 702, var_export($login, true));
    $check('recent.diagExposed', count(ExistingCustomer::recentLogins(20)) === 1);

    // A second page load with the same (now used) token does nothing.
    $rec2 = new Recorder();
    $r2 = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec2));
    $check('recent.usedTokenIsInert', ($r2['action'] ?? '') === 'none' && ($r2['reason'] ?? '') === 'no_intent' && count($rec2->sso) === 0, var_export($r2, true));
    $check('unknownToken.none', (ExistingCustomer::resolve(CLIENT, str_repeat('0', 32), makeCtx(new Recorder()))['action'] ?? '') === 'none');

    // ===================================================================
    // 5. newest / oldest end to end.
    // ===================================================================
    echo "\n--- newest / oldest ---\n";
    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec));
    $check('newest.picks703', ($r['service_id'] ?? 0) === 703, var_export($r, true));

    seed(['policy' => 'oldest']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec));
    $check('oldest.picks701', ($r['service_id'] ?? 0) === 701, var_export($r, true));

    // ===================================================================
    // 6. Capacity filter.
    // ===================================================================
    echo "\n--- capacity filter ---\n";
    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [703 => 0.0, 702 => 12.5, 701 => 3.0]));
    $check('capacity.skipsZeroCredit', ($r['service_id'] ?? 0) === 702, var_export($r, true));
    $check('capacity.zeroOneNeverSsoed', count($rec->sso) === 1 && $rec->sso[0]['sid'] === 702);

    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [])); // every read fails → keep all
    $check('capacity.readFailureKeepsAll', ($r['service_id'] ?? 0) === 703, var_export($r, true));

    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [701 => 0.0, 702 => 0.0, 703 => 0.0]));
    $check('capacity.allZeroKeepsUnfilteredList', ($r['service_id'] ?? 0) === 703, var_export($r, true));

    seed(['policy' => 'newest']);
    seedService(701, CLIENT, PID_MONTHLY, 'Active', '2026-01-01', 'tenant-701');
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [701 => 0.0]));
    $check('capacity.singleCandidateSkipsUsageRead', count($rec->usage) === 0 && ($r['service_id'] ?? 0) === 701, json_encode($rec->usage));

    seed(['policy' => 'newest']);
    for ($i = 1; $i <= 6; $i++) {
        seedService(720 + $i, CLIENT, PID_MONTHLY, 'Active', '2026-01-0' . $i, 'tenant-72' . $i);
    }
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [726 => 0.0]));
    $check('capacity.sixCandidatesSkipsUsageRead', count($rec->usage) === 0 && ($r['service_id'] ?? 0) === 726, json_encode($rec->usage));

    // creditsAvailableFromUsage — the platform-usage → number mapping.
    $check('credits.noBalancesIsUnknown', ExistingCustomer::creditsAvailableFromUsage(['usage' => []]) === null);
    $check('credits.sumsPools', ExistingCustomer::creditsAvailableFromUsage(['balances' => ['by_workspace' => [[
        'included_remaining' => 5, 'rollover_remaining' => 2.5, 'topup_available' => 0, 'free_remaining' => 1,
    ]]]]) === 8.5);
    $check('credits.allZeroIsZero', ExistingCustomer::creditsAvailableFromUsage(['balances' => ['by_workspace' => [[
        'included_remaining' => 0, 'topup_available' => 0, 'free_remaining' => 0,
    ]]]]) === 0.0);
    $unl = ExistingCustomer::creditsAvailableFromUsage(['balances' => ['by_workspace' => [['included_remaining' => 0, 'free_remaining' => null]]]]);
    $check('credits.unlimitedFreeLaneCounts', $unl !== null && $unl > 0);
    $check('credits.emptyWorkspaceRowIsUnknown', ExistingCustomer::creditsAvailableFromUsage(['balances' => ['by_workspace' => [['caps' => []]]]]) === null);

    // ===================================================================
    // 7. 409 / 410 fall through to the next candidate; other failures stop.
    // ===================================================================
    echo "\n--- sso fallback ---\n";
    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [703 => ['ok' => false, 'status' => 409, 'error' => 'suspended']]));
    $check('fallback.409TriesNext', ($r['service_id'] ?? 0) === 702 && ($r['action'] ?? '') === 'redirect', var_export($r, true));
    $check('fallback.ssoOrder', array_column($rec->sso, 'sid') === [703, 702], json_encode(array_column($rec->sso, 'sid')));
    $check('fallback.intentConsumedBySecond', (int) (intent()['service_id'] ?? 0) === 702 && !empty(intent()['used_at']));
    $check('fallback.diagNotesSkip', strpos((string) (lastLogin()['note'] ?? ''), '#703') !== false, var_export(lastLogin(), true));

    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [703 => ['ok' => false, 'status' => 410, 'error' => 'terminated'], 702 => ['ok' => false, 'status' => 409, 'error' => 'suspended']]));
    $check('fallback.410Then409ThenThird', ($r['service_id'] ?? 0) === 701, var_export($r, true));

    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [703 => ['ok' => false, 'status' => 502, 'error' => 'bad_gateway']]));
    $check('fallback.otherFailureStops', ($r['action'] ?? '') === 'none' && ($r['reason'] ?? '') === 'sso_failed' && count($rec->sso) === 1, var_export($r, true));
    $check('fallback.otherFailureKeepsIntent', empty(intent()['used_at']) && empty(intent()['service_id']));
    $check('fallback.otherFailureDiag', (lastLogin()['outcome'] ?? '') === 'failed');

    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $refuse = ['ok' => false, 'status' => 409, 'error' => 'suspended'];
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx(new Recorder(), [701 => $refuse, 702 => $refuse, 703 => $refuse]));
    $check('fallback.allRefusedIsNone', ($r['action'] ?? '') === 'none' && ($r['reason'] ?? '') === 'all_refused', var_export($r, true));
    $check('fallback.allRefusedKeepsIntent', empty(intent()['used_at']));

    // ===================================================================
    // 8. Zero workspaces → free Starter Product: AddOrder → bind →
    //    AcceptOrder → SSO, for THIS client, with the prompt attached.
    // ===================================================================
    echo "\n--- zero workspaces: free starter ---\n";
    seed(['policy' => 'recent', 'starter' => (string) PID_FREE]);
    seedIntent();
    // The stub cannot provision; pretend CreateAccount ran inside AcceptOrder:
    // tenant stored + intent consumed (initial_prompt went on platform-create).
    Capsule::$store['tblcustomfieldsvalues'][] = ['fieldid' => 1, 'relid' => 777, 'value' => 'tenant-777'];
    $rec = new Recorder();
    $ctx = makeCtx($rec, [], [], [
        'AddOrder'    => ['result' => 'success', 'orderid' => 900, 'invoiceid' => 0, 'productids' => '777'],
        'AcceptOrder' => function (array $params) {
            Capsule::$store['tblhosting'][] = ['id' => 777, 'userid' => CLIENT, 'packageid' => PID_FREE, 'domainstatus' => 'Active', 'regdate' => date('Y-m-d'), 'orderid' => 900];
            PromptBox::markUsedForService(777); // what CreateAccount does after sending initial_prompt
            return ['result' => 'success'];
        },
    ]);
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, $ctx);
    $check('starter.redirect', ($r['action'] ?? '') === 'redirect' && ($r['url'] ?? '') === 'https://app.example.invalid/sso/777', var_export($r, true));
    $check('starter.outcome', ($r['outcome'] ?? '') === 'new_workspace' && ($r['service_id'] ?? 0) === 777 && !empty($r['clear_token']));
    // bind BEFORE AcceptOrder (so CreateAccount finds the prompt); the consume
    // inside AcceptOrder is the simulated CreateAccount; the trailing consume
    // is the resolver's own idempotent mark after the SSO mint.
    $check('starter.callOrder', $rec->events === ['localApi:AddOrder', 'bind', 'localApi:AcceptOrder', 'consume', 'sso:777', 'consume'], implode(',', $rec->events));
    $order = callOf($rec, 'AddOrder');
    $check('starter.addOrder.thisClient', ($order['clientid'] ?? 0) === CLIENT);
    $check('starter.addOrder.params', ($order['pid'] ?? null) === [PID_FREE] && ($order['billingcycle'] ?? null) === ['free'] && ($order['paymentmethod'] ?? '') === 'banktransfer');
    $check('starter.addOrder.emailsAsExpress', ($order['noemail'] ?? null) === true && ($order['noinvoiceemail'] ?? null) === true);
    $accept = callOf($rec, 'AcceptOrder');
    $check('starter.acceptOrder.autosetup', ($accept['orderid'] ?? 0) === 900 && ($accept['autosetup'] ?? null) === true && ($accept['sendemail'] ?? null) === true);
    $check('starter.ssoWithoutPromptWhenCreateAccountTookIt', count($rec->sso) === 1 && $rec->sso[0]['sid'] === 777 && $rec->sso[0]['prompt'] === null, var_export($rec->sso, true));
    $check('starter.intentBoundAndUsed', (int) (intent()['service_id'] ?? 0) === 777 && !empty(intent()['used_at']));
    $check('starter.launchRecorded', launchOf(777) !== null);
    $check('starter.diag', (lastLogin()['outcome'] ?? '') === 'new_workspace' && (int) (lastLogin()['service_id'] ?? 0) === 777);

    // Provisioning did NOT consume the prompt (bind raced) → it rides the SSO instead.
    seed(['starter' => (string) PID_FREE]);
    seedIntent();
    Capsule::$store['tblcustomfieldsvalues'][] = ['fieldid' => 1, 'relid' => 778, 'value' => 'tenant-778'];
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [], [
        'AddOrder'    => ['result' => 'success', 'orderid' => 901, 'productids' => '778'],
        'AcceptOrder' => function () {
            Capsule::$store['tblhosting'][] = ['id' => 778, 'userid' => CLIENT, 'packageid' => PID_FREE, 'domainstatus' => 'Active', 'regdate' => date('Y-m-d'), 'orderid' => 901];
            return ['result' => 'success'];
        },
    ]));
    $check('starter.unconsumedPromptRidesSso', ($r['service_id'] ?? 0) === 778 && ($rec->sso[0]['prompt'] ?? null) === PROMPT, var_export($rec->sso, true));
    $check('starter.thenConsumed', !empty(intent()['used_at']));

    // Zero-priced recurring product counts as free too.
    seed(['starter' => (string) PID_ZERO_PRICED]);
    seedIntent();
    Capsule::$store['tblcustomfieldsvalues'][] = ['fieldid' => 1, 'relid' => 779, 'value' => 'tenant-779'];
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [], [
        'AddOrder'    => ['result' => 'success', 'orderid' => 902, 'productids' => '779'],
        'AcceptOrder' => function () {
            Capsule::$store['tblhosting'][] = ['id' => 779, 'userid' => CLIENT, 'packageid' => PID_ZERO_PRICED, 'domainstatus' => 'Active', 'regdate' => date('Y-m-d'), 'orderid' => 902];
            return ['result' => 'success'];
        },
    ]));
    $check('isFree.zeroPricedRecurring', ExpressSignup::isFreeProduct(PID_ZERO_PRICED, CLIENT) === true);
    $check('isFree.pricedRecurring', ExpressSignup::isFreeProduct(PID_MONTHLY, CLIENT) === false);
    $check('isFree.freePaytype', ExpressSignup::isFreeProduct(PID_FREE, CLIENT) === true);
    $check('isFree.unknownProduct', ExpressSignup::isFreeProduct(4242, CLIENT) === false);
    $check('starter.zeroPricedRecurringOrdered', ($r['service_id'] ?? 0) === 779 && (callOf($rec, 'AddOrder')['billingcycle'] ?? null) === ['monthly'], var_export($r, true));

    // AddOrder fails outright → cart with the starter preselected, token kept.
    seed(['starter' => (string) PID_FREE]);
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [], [
        'AddOrder' => ['result' => 'error', 'message' => 'Payment method not set'],
    ]));
    $check('starter.addOrderFailureFallsBackToCart', ($r['action'] ?? '') === 'redirect' && ($r['url'] ?? '') === SYSTEM_URL . '/cart.php?a=add&pid=' . PID_FREE . '&swzp=' . TOKEN, var_export($r, true));
    $check('starter.addOrderFailureKeepsToken', empty($r['clear_token']) && empty(intent()['used_at']));
    $check('starter.addOrderFailureNeverAccepts', !in_array('localApi:AcceptOrder', $rec->events, true));

    // No active payment gateway → cart, no order attempted.
    seed(['starter' => (string) PID_FREE]);
    seedIntent();
    Capsule::$store['tblpaymentgateways'] = [];
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec));
    $check('starter.noGatewayGoesToCart', ($r['outcome'] ?? '') === 'cart' && count($rec->calls) === 0, var_export($r, true));

    // Provisioned but no tenant yet (slow provision) → service page, token stays bound for CreateAccount.
    seed(['starter' => (string) PID_FREE]);
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec, [], [], [
        'AddOrder' => ['result' => 'success', 'orderid' => 903, 'productids' => '780'],
    ]));
    $check('starter.noTenantLandsOnServicePage', ($r['url'] ?? '') === SYSTEM_URL . '/clientarea.php?action=productdetails&id=780' && count($rec->sso) === 0, var_export($r, true));
    $check('starter.noTenantIntentStaysBound', (int) (intent()['service_id'] ?? 0) === 780 && empty(intent()['used_at']));

    // ===================================================================
    // 9. Zero workspaces → paid / unset starter → cart.
    // ===================================================================
    echo "\n--- zero workspaces: paid or unset starter ---\n";
    seed(['starter' => (string) PID_MONTHLY]);
    seedIntent(TOKEN, PROMPT, PID_FREE);
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec));
    $check('paidStarter.cartWithStarterPid', ($r['action'] ?? '') === 'redirect' && ($r['url'] ?? '') === SYSTEM_URL . '/cart.php?a=add&pid=' . PID_MONTHLY . '&swzp=' . TOKEN, var_export($r, true));
    $check('paidStarter.noOrderPlaced', count($rec->calls) === 0 && count($rec->sso) === 0);
    $check('paidStarter.tokenKept', empty($r['clear_token']) && empty(intent()['used_at']));
    $check('paidStarter.diag', (lastLogin()['outcome'] ?? '') === 'cart');

    seed(['starter' => '']);
    seedIntent(TOKEN, PROMPT, PID_FREE);
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx(new Recorder()));
    $check('unsetStarter.cartWithIntentPid', ($r['url'] ?? '') === SYSTEM_URL . '/cart.php?a=add&pid=' . PID_FREE . '&swzp=' . TOKEN, var_export($r, true));

    seed(['starter' => (string) PID_NOT_SWARMZ]); // misconfigured → treated as unset
    seedIntent(TOKEN, PROMPT, PID_FREE);
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec));
    $check('nonSwarmzStarter.cartWithIntentPid', ($r['url'] ?? '') === SYSTEM_URL . '/cart.php?a=add&pid=' . PID_FREE . '&swzp=' . TOKEN && count($rec->calls) === 0, var_export($r, true));

    // Only non-eligible services (suspended) → same zero-workspace branch.
    seed(['starter' => '']);
    seedService(690, CLIENT, PID_MONTHLY, 'Suspended', '2026-01-01', 'tenant-690');
    seedIntent(TOKEN, PROMPT, PID_FREE);
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx(new Recorder()));
    $check('suspendedOnly.goesToCart', ($r['outcome'] ?? '') === 'cart', var_export($r, true));

    // ===================================================================
    // 10. `ask` policy → chooser; its two buttons.
    // ===================================================================
    echo "\n--- ask policy ---\n";
    seed(['policy' => 'ask']);
    seedThreeWorkspaces();
    seedIntent();
    $rec = new Recorder();
    $r = ExistingCustomer::resolve(CLIENT, TOKEN, makeCtx($rec));
    $check('ask.choose', ($r['action'] ?? '') === 'choose' && ($r['url'] ?? '') === SYSTEM_URL . '/index.php?m=swarmz&a=choose', var_export($r, true));
    $check('ask.candidatesOrderedRecent', $ids($r['candidates'] ?? []) === [702, 701, 703], json_encode($ids($r['candidates'] ?? [])));
    $check('ask.noSsoNoConsume', count($rec->sso) === 0 && empty(intent()['used_at']) && empty($r['clear_token']));
    $check('ask.diag', (lastLogin()['outcome'] ?? '') === 'chooser');

    $rec = new Recorder();
    $r = ExistingCustomer::launchService(CLIENT, 701, TOKEN, makeCtx($rec));
    $check('ask.buildHere', ($r['action'] ?? '') === 'redirect' && ($r['service_id'] ?? 0) === 701 && ($rec->sso[0]['prompt'] ?? null) === PROMPT, var_export($r, true));
    $check('ask.buildHereConsumes', (int) (intent()['service_id'] ?? 0) === 701 && !empty(intent()['used_at']) && !empty($r['clear_token']));

    $r = ExistingCustomer::launchService(CLIENT, 607, TOKEN, makeCtx(new Recorder()));
    $check('ask.buildHereRefusesForeignService', ($r['action'] ?? '') === 'none' && ($r['reason'] ?? '') === 'not_eligible', var_export($r, true));

    // Without a pending prompt the button still opens the workspace (no prompt, nothing consumed).
    $rec = new Recorder();
    $r = ExistingCustomer::launchService(CLIENT, 702, '', makeCtx($rec));
    $check('ask.buildHereWithoutToken', ($r['action'] ?? '') === 'redirect' && count($rec->sso) === 1 && $rec->sso[0]['prompt'] === null && empty($r['clear_token']), var_export($r, true));

    seed(['policy' => 'ask', 'starter' => (string) PID_MONTHLY]);
    seedThreeWorkspaces();
    seedIntent();
    $r = ExistingCustomer::startNew(CLIENT, TOKEN, makeCtx(new Recorder()));
    $check('ask.startNewRunsStarterBranch', ($r['url'] ?? '') === SYSTEM_URL . '/cart.php?a=add&pid=' . PID_MONTHLY . '&swzp=' . TOKEN, var_export($r, true));
    $r = ExistingCustomer::startNew(CLIENT, '', makeCtx(new Recorder()));
    $check('ask.startNewWithoutTokenPreselectsOnly', ($r['url'] ?? '') === SYSTEM_URL . '/cart.php?a=add&pid=' . PID_MONTHLY, var_export($r, true));
    seed(['policy' => 'ask', 'starter' => '']);
    $r = ExistingCustomer::startNew(CLIENT, '', makeCtx(new Recorder()));
    $check('ask.startNewNothingToOrder', ($r['action'] ?? '') === 'none', var_export($r, true));

    // ===================================================================
    // 11. Auto-open after classic checkout (no initial_prompt on the SSO).
    // ===================================================================
    echo "\n--- auto-open after checkout ---\n";
    seed();
    seedService(777, CLIENT, PID_FREE, 'Active', date('Y-m-d'), 'tenant-777');
    seedIntent(TOKEN, PROMPT, PID_FREE, 777, date('Y-m-d H:i:s')); // bound + consumed by CreateAccount
    $rec = new Recorder();
    $r = ExistingCustomer::autoOpenAfterCheckout(CLIENT, TOKEN, 0, makeCtx($rec));
    $check('autoopen.redirect', ($r['action'] ?? '') === 'redirect' && ($r['url'] ?? '') === 'https://app.example.invalid/sso/777' && !empty($r['clear_token']), var_export($r, true));
    $check('autoopen.ssoWithoutPrompt', count($rec->sso) === 1 && $rec->sso[0]['sid'] === 777 && $rec->sso[0]['prompt'] === null, var_export($rec->sso, true));
    $check('autoopen.outcome', ($r['outcome'] ?? '') === 'checkout_open' && (lastLogin()['outcome'] ?? '') === 'checkout_open');
    $check('autoopen.launchRecorded', launchOf(777) !== null);

    // Session-bound service id when the token is already gone from the session.
    seed();
    seedService(778, CLIENT, PID_FREE, 'Active', date('Y-m-d'), 'tenant-778');
    $rec = new Recorder();
    $r = ExistingCustomer::autoOpenAfterCheckout(CLIENT, '', 778, makeCtx($rec));
    $check('autoopen.viaSessionServiceId', ($r['action'] ?? '') === 'redirect' && ($r['service_id'] ?? 0) === 778, var_export($r, true));

    // Paid product: still Pending / no tenant → nothing happens.
    seed();
    seedService(779, CLIENT, PID_MONTHLY, 'Pending', date('Y-m-d'), null);
    seedIntent(TOKEN, PROMPT, PID_MONTHLY, 779);
    $rec = new Recorder();
    $r = ExistingCustomer::autoOpenAfterCheckout(CLIENT, TOKEN, 779, makeCtx($rec));
    $check('autoopen.pendingServiceIsLeftAlone', ($r['action'] ?? '') === 'none' && count($rec->sso) === 0, var_export($r, true));

    // Someone else's service id can never be opened.
    seed();
    seedService(780, OTHER_CLIENT, PID_FREE, 'Active', date('Y-m-d'), 'tenant-780');
    $r = ExistingCustomer::autoOpenAfterCheckout(CLIENT, '', 780, makeCtx(new Recorder()));
    $check('autoopen.foreignServiceRefused', ($r['action'] ?? '') === 'none', var_export($r, true));

    // SSO failure → none (no redirect), diagnostics row says so.
    seed();
    seedService(781, CLIENT, PID_FREE, 'Active', date('Y-m-d'), 'tenant-781');
    $r = ExistingCustomer::autoOpenAfterCheckout(CLIENT, '', 781, makeCtx(new Recorder(), [781 => ['ok' => false, 'status' => 404, 'error' => 'tenant_not_found']]));
    $check('autoopen.ssoFailureIsNone', ($r['action'] ?? '') === 'none' && (lastLogin()['outcome'] ?? '') === 'checkout_failed', var_export($r, true));

    // ===================================================================
    // 12. Robustness: a throwing boundary never escapes resolve().
    // ===================================================================
    echo "\n--- robustness ---\n";
    seed(['policy' => 'newest']);
    seedThreeWorkspaces();
    seedIntent();
    $threw = false;
    try {
        $r = ExistingCustomer::resolve(CLIENT, TOKEN, [
            'sso' => function () { throw new \RuntimeException('boom'); },
            'usage' => function () { return null; },
            'localApi' => function () { return ['result' => 'success']; },
        ]);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check('robust.throwingSsoIsContained', $threw === false && ($r['action'] ?? '') === 'none', var_export($r ?? null, true));
    $check('robust.intentUntouched', empty(intent()['used_at']));
    $check('robust.diagPurgeNeverThrows', ExistingCustomer::purgeStale() >= 0);

    // Keys never appear in the recorded log lines (every log line is redacted on request).
    $leak = false;
    foreach ($GLOBALS['__logLines'] as $line) {
        if (strpos(json_encode($line) ?: '', 'sk_live_test') !== false) {
            $leak = true;
        }
    }
    $check('log.noKeyLeak', $leak === false);

    echo "\n";
    if ($failed > 0) {
        echo "FAILED: $failed check(s).\n";
        exit(1);
    }
    echo "All checks passed.\n";
    exit(0);
}
