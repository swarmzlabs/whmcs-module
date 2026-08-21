<?php
/**
 * Swarmz WHMCS module — "Frictionless onboarding" (express signup) regression
 * test.
 *
 * Drives the REAL ExpressSignup::run() against the REAL PromptBox and
 * Helpers classes. Only the two boundaries ExpressSignup takes via $ctx are
 * faked — localAPI() and the platform-sso mint — via a scripted, recording
 * `localApi` callable and a scripted `sso` callable. Everything else (the
 * gate, validation order, the stricter rate limit, intent creation +
 * binding, tenant lookup, and the redacting log wrapper) is the production
 * code path, exercised against an in-memory Capsule stub extending
 * test/console-token.php's catch-all pattern with scripted rows for
 * tbladdonmodules / tblproducts / tblpaymentgateways / tblhosting /
 * tblcustomfields(values) / mod_swarmz_prompt_intents / tblconfiguration.
 *
 * Usage:  php test/express-harness.php
 */

declare(strict_types=1);

namespace WHMCS\Database {

    /**
     * In-memory stand-in for WHMCS's Capsule (Eloquent query builder facade).
     * Generic enough to answer every query chain ExpressSignup + PromptBox +
     * Helpers issue against it: where/whereIn/whereNull/whereNotNull/join/
     * orderBy/limit, first/get/count/exists, insert/insertGetId/update/
     * updateOrInsert/delete, plus schema()->hasTable()/create(). Joins are
     * resolved by cross-product (same approach as test/smoke.php's stub),
     * not real relational planning — plenty for the single-join query this
     * module issues (tblcustomfieldsvalues ⋈ tblcustomfields).
     */
    class Capsule
    {
        /** @var array<string,array<int,array<string,mixed>>> table => rows */
        public static $store = [];

        /** @var array<string,bool> tables ensureSchema()/hasTable() knows about */
        public static $tables = [];

        /** @var array<string,array<string,bool>> table => column names declared via schema()->create()/table() */
        public static $columns = [];

        /** @var array<string,int> */
        private static $autoInc = [];

        /**
         * Fired as ($table, $data) after every update() that actually
         * matched a row — the harness uses this to observe
         * PromptBox::bindToService() without instrumenting PromptBox itself.
         *
         * @var callable|null
         */
        public static $onUpdate = null;

        public static function reset(): void
        {
            self::$store = [];
            self::$tables = [];
            self::$columns = [];
            self::$autoInc = [];
            self::$onUpdate = null;
        }

        public static function table(string $name): FakeQuery
        {
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

        /**
         * Alter an existing table — exercises PromptBox::ensureSchema()'s
         * additive, hasColumn-guarded ADD COLUMN path. Rows are plain assoc
         * arrays in this fake, so there's nothing to physically alter; this
         * only needs to make the new column name visible to hasColumn().
         */
        public function table(string $name, $callback): void
        {
            $this->runBlueprint($name, $callback);
        }

        /** True once a column has been declared via create() or table(). */
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

    /**
     * Records the column names a create()/table() closure declares (e.g.
     * $table->string('step', 40)->nullable()) so hasColumn() can answer
     * accurately. Every column-defining call's first argument is the column
     * name; modifier calls with no first argument (->nullable(), ->unique())
     * or a non-string first argument (->index(['ip', 'created_at'])) are
     * harmlessly ignored.
     */
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
        /** @var string */
        private $table;

        /** @var array<int,array{0:string,1:string,2:mixed}> */
        private $wheres = [];

        /** @var array<int,array{0:string,1:string,2:string,3:?string}> */
        private $joins = [];

        /** @var array{0:string,1:string}|null */
        private $orderBy = null;

        /** @var int|null */
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

        public function join(string $table, string $lhs, string $op = '=', ?string $rhs = null): self
        {
            $this->joins[] = [$table, $lhs, $op, $rhs];
            return $this;
        }

        public function leftJoin(string $table, string $lhs, string $op = '=', ?string $rhs = null): self
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

        /** @return array<int,array<string,mixed>> */
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
                            // Base-row keys win on a name clash.
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

        /** @return array<int,object> */
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

    // Every logModuleCall(...) the module makes lands here, verbatim, so the
    // "password never appears in the recorded log lines" assertion can scan
    // the actual data that would have reached tblmodulelog.
    $GLOBALS['__logLines'] = [];

    function logModuleCall($module, $action, $request, $response, $processedResponse = '', $replaceVars = [])
    {
        $GLOBALS['__logLines'][] = [
            'module'   => $module,
            'action'   => $action,
            'request'  => $request,
            'response' => $response,
        ];
    }

    $root = realpath(__DIR__ . '/..');
    require_once $root . '/modules/servers/swarmz/lib/Exceptions.php';
    require_once $root . '/modules/servers/swarmz/lib/Api.php';
    require_once $root . '/modules/servers/swarmz/lib/Helpers.php';
    require_once $root . '/modules/addons/swarmz/lib/PromptBox.php';
    require_once $root . '/modules/addons/swarmz/lib/ExpressSignup.php';

    use WHMCS\Database\Capsule;
    use WHMCS\Module\Addon\Swarmz\ExpressSignup;
    use WHMCS\Module\Addon\Swarmz\PromptBox;

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
    const PID_MONTHLY = 12;   // tblproducts.paytype = recurring → billingcycle 'monthly'
    const PID_FREE = 13;      // tblproducts.paytype = free      → billingcycle 'free'
    const PID_NOT_SWARMZ = 99; // a real product, but not this module's

    /** Reset the fake DB to a fresh, express-enabled baseline. */
    function seed(array $settings = []): void
    {
        Capsule::reset();
        Capsule::$tables[PromptBox::TABLE] = true;

        Capsule::$store['tblconfiguration'] = [
            ['setting' => 'SystemURL', 'value' => 'https://shop.example.invalid'],
        ];
        Capsule::$store['tbladdonmodules'] = [
            ['module' => 'swarmz', 'setting' => 'Express Signup', 'value' => $settings['express_signup'] ?? 'on'],
            ['module' => 'swarmz', 'setting' => 'Express ToS URL', 'value' => $settings['express_tos_url'] ?? ''],
            ['module' => 'swarmz', 'setting' => 'API Key', 'value' => 'sk_live_test'],
            ['module' => 'swarmz', 'setting' => 'API Base URL', 'value' => 'https://api.example.invalid'],
        ];
        // Absent unless a test opts in, so the default-reader path (no stored
        // row at all) is what most cases exercise, same as a host who never
        // touched this new v1.22.0 setting.
        if (isset($settings['express_min_password'])) {
            Capsule::$store['tbladdonmodules'][] = [
                'module' => 'swarmz', 'setting' => 'Express Min Password',
                'value'  => (string) $settings['express_min_password'],
            ];
        }
        Capsule::$store['tblproducts'] = [
            ['id' => PID_MONTHLY, 'servertype' => 'swarmz', 'paytype' => 'recurring'],
            ['id' => PID_FREE, 'servertype' => 'swarmz', 'paytype' => 'free'],
            ['id' => PID_NOT_SWARMZ, 'servertype' => 'cpanel', 'paytype' => 'recurring'],
        ];
        Capsule::$store['tblpaymentgateways'] = [
            ['gateway' => 'teststripe', 'setting' => 'name', 'value' => 'Card', 'order' => 1],
        ];
        Capsule::$store['tblhosting'] = [];
        Capsule::$store['tblcustomfields'] = [];
        Capsule::$store['tblcustomfieldsvalues'] = [];
        Capsule::$store[PromptBox::TABLE] = [];
    }

    /** Give a service a tenant id, as CreateAccount would after provisioning. */
    function seedTenant(int $serviceId, string $tenantId): void
    {
        Capsule::$store['tblcustomfields'][] = ['id' => 1, 'type' => 'product', 'fieldname' => 'Swarmz Tenant ID'];
        Capsule::$store['tblcustomfieldsvalues'][] = ['fieldid' => 1, 'relid' => $serviceId, 'value' => $tenantId];
    }

    /** Pre-fill the intents table with $n hits from $ip in the last hour. */
    function seedRecentIntents(string $ip, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Capsule::$store[PromptBox::TABLE][] = [
                'token' => 'preexisting-' . $i,
                'prompt' => 'x',
                'pid' => PID_MONTHLY,
                'service_id' => null,
                'order_id' => null,
                'ip' => $ip,
                'created_at' => date('Y-m-d H:i:s'),
                'bound_at' => null,
                'used_at' => null,
            ];
        }
    }

    function seedRecentExpressAttempts(string $ip, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Capsule::$store[PromptBox::ATTEMPTS_TABLE][] = [
                'ip' => $ip,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    function countExpressAttempts(string $ip): int
    {
        $n = 0;
        foreach (Capsule::$store[PromptBox::ATTEMPTS_TABLE] ?? [] as $row) {
            if (($row['ip'] ?? '') === $ip) {
                $n++;
            }
        }
        return $n;
    }

    function baseInput(array $overrides = []): array
    {
        return array_merge([
            'prompt'   => 'Build me a bakery landing page',
            'pid'      => PID_MONTHLY,
            'email'    => 'jane.doe+promo@example.com',
            'password' => 'correct-horse',
            'ip'       => '203.0.113.7',
        ], $overrides);
    }

    /** Records every localApi call + the sso call, in order, for assertions. */
    class Recorder
    {
        /** @var array<int,array{action:string,params:array}> */
        public $calls = [];
        /** @var array<int,string> */
        public $events = [];
    }

    /**
     * @param array<string,array|callable> $script action => response array,
     *        or a callable(params): array for a dynamic response. Actions
     *        not listed default to ['result' => 'success'].
     */
    function makeCtx(Recorder $rec, array $script, $ssoResult = 'DEFAULT'): array
    {
        Capsule::$onUpdate = function (string $table, array $data) use ($rec) {
            if ($table === PromptBox::TABLE && array_key_exists('service_id', $data)) {
                $rec->events[] = 'bind';
            }
        };
        return [
            'localApi' => function (string $action, array $params) use ($rec, $script): array {
                $rec->calls[] = ['action' => $action, 'params' => $params];
                $rec->events[] = 'localApi:' . $action;
                if (isset($script[$action])) {
                    $resp = $script[$action];
                    return is_callable($resp) ? $resp($params) : $resp;
                }
                return ['result' => 'success'];
            },
            'sso' => function (string $externalRef) use ($rec, $ssoResult) {
                $rec->events[] = 'sso';
                return $ssoResult === 'DEFAULT' ? 'https://app.example.invalid/sso/mock-token' : $ssoResult;
            },
        ];
    }

    // ===================================================================
    // 1. Happy path — exact call order, response shape, AddClient params.
    // ===================================================================
    echo "--- happy path ---\n";
    seed();
    seedTenant(777, 'tenant-abc-123');
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 501],
        'AddOrder'          => ['result' => 'success', 'orderid' => 900, 'invoiceid' => 0, 'productids' => '777'],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);

    $check('happy.ok', ($result['ok'] ?? false) === true, var_export($result, true));
    $check('happy.redirect', ($result['redirect'] ?? '') === 'https://app.example.invalid/sso/mock-token');
    $check('happy.callOrder', $rec->events === [
        'localApi:GetClientsDetails', 'localApi:AddClient', 'localApi:AddOrder', 'bind', 'localApi:AcceptOrder', 'sso',
    ], implode(',', $rec->events));

    $addClientCall = null;
    foreach ($rec->calls as $c) {
        if ($c['action'] === 'AddClient') {
            $addClientCall = $c['params'];
        }
    }
    $check('happy.addClient.skipvalidation', ($addClientCall['skipvalidation'] ?? null) === true);
    $check('happy.addClient.firstnameNonEmpty', isset($addClientCall['firstname']) && is_string($addClientCall['firstname']) && $addClientCall['firstname'] !== '');
    $check('happy.addClient.firstnameDerived', ($addClientCall['firstname'] ?? null) === 'Jane.doe', (string) ($addClientCall['firstname'] ?? 'null'));
    $check('happy.addClient.lastnameNonEmpty', array_key_exists('lastname', $addClientCall) && $addClientCall['lastname'] !== '' && is_string($addClientCall['lastname']));
    $check('happy.addClient.passwordCarried', ($addClientCall['password2'] ?? null) === 'correct-horse');

    $intentRows = Capsule::$store[PromptBox::TABLE];
    $bound = null;
    foreach ($intentRows as $row) {
        if (($row['service_id'] ?? null) === 777) {
            $bound = $row;
        }
    }
    $check('happy.intentBoundToService', $bound !== null && $bound['prompt'] === 'Build me a bakery landing page');

    // ===================================================================
    // 2. Duplicate email → account_exists (no client/order created).
    // ===================================================================
    echo "\n--- duplicate email ---\n";
    seed();
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'success', 'clientid' => 4, 'email' => 'jane.doe+promo@example.com'],
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('dup.error', ($result['error'] ?? '') === 'account_exists', var_export($result, true));
    $check('dup.ok', ($result['ok'] ?? true) === false);
    $check('dup.noAddClient', $rec->events === ['localApi:GetClientsDetails'], implode(',', $rec->events));

    // ===================================================================
    // 3. Express disabled → express_disabled, zero side effects.
    // ===================================================================
    echo "\n--- express disabled ---\n";
    seed(['express_signup' => 'off']);
    $rec = new Recorder();
    $ctx = makeCtx($rec, []);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('disabled.error', ($result['error'] ?? '') === 'express_disabled', var_export($result, true));
    $check('disabled.noCalls', count($rec->calls) === 0);

    // ===================================================================
    // 4. Rate limited → rate_limited (before any localApi call).
    // ===================================================================
    echo "\n--- rate limited ---\n";
    seed();
    // The ceiling is counted from the express ATTEMPT log, not intent rows —
    // seed the attempt table to the ceiling for this IP.
    seedRecentExpressAttempts('203.0.113.7', PromptBox::EXPRESS_RATE_LIMIT_PER_HOUR);
    $rec = new Recorder();
    $ctx = makeCtx($rec, []);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('ratelimit.error', ($result['error'] ?? '') === 'rate_limited', var_export($result, true));
    $check('ratelimit.noCalls', count($rec->calls) === 0);
    // Pre-existing INTENT rows must NOT throttle express (the old, bypassable
    // behavior) — only the attempt log does.
    seed();
    seedRecentIntents('198.51.100.9', PromptBox::EXPRESS_RATE_LIMIT_PER_HOUR + 5);
    $result = ExpressSignup::run(baseInput(['ip' => '198.51.100.9']), makeCtx(new Recorder(), []));
    $check('ratelimit.intentRowsDoNotThrottle', ($result['error'] ?? '') !== 'rate_limited', var_export($result, true));

    // ===================================================================
    // 4b. BLOCKER regression: a duplicate-email 409 still counts against the
    // per-IP ceiling. Previously account_exists returned before any row was
    // written, so looping a known email bypassed the limit forever.
    // ===================================================================
    echo "\n--- rate limit: account_exists is throttled ---\n";
    seed();
    $dupCtx = makeCtx(new Recorder(), ['GetClientsDetails' => ['result' => 'success', 'id' => 42]]);
    for ($i = 0; $i < PromptBox::EXPRESS_RATE_LIMIT_PER_HOUR; $i++) {
        $r = ExpressSignup::run(baseInput(['ip' => '203.0.113.50']), $dupCtx);
        if (($r['error'] ?? '') !== 'account_exists') { break; }
    }
    $check('dupLoop.attemptsRecorded', countExpressAttempts('203.0.113.50') === PromptBox::EXPRESS_RATE_LIMIT_PER_HOUR, 'recorded=' . countExpressAttempts('203.0.113.50'));
    $overLimit = ExpressSignup::run(baseInput(['ip' => '203.0.113.50']), $dupCtx);
    $check('dupLoop.eventuallyRateLimited', ($overLimit['error'] ?? '') === 'rate_limited', var_export($overLimit, true));

    // ===================================================================
    // 5. Invalid email / weak password / unknown product — validation.
    // ===================================================================
    echo "\n--- validation ---\n";
    seed();
    $result = ExpressSignup::run(baseInput(['email' => 'not-an-email']), makeCtx(new Recorder(), []));
    $check('validate.invalidEmail', ($result['error'] ?? '') === 'invalid_email', var_export($result, true));

    seed();
    $result = ExpressSignup::run(baseInput(['password' => 'short']), makeCtx(new Recorder(), []));
    $check('validate.weakPassword', ($result['error'] ?? '') === 'weak_password', var_export($result, true));

    seed();
    $result = ExpressSignup::run(baseInput(['prompt' => '']), makeCtx(new Recorder(), []));
    $check('validate.emptyPrompt', ($result['error'] ?? '') === 'empty_prompt', var_export($result, true));

    seed();
    $result = ExpressSignup::run(baseInput(['pid' => PID_NOT_SWARMZ]), makeCtx(new Recorder(), []));
    $check('validate.unknownProduct', ($result['error'] ?? '') === 'unknown_product', var_export($result, true));

    // Password ceiling — an over-long blob is rejected before any live call.
    seed();
    $rec = new Recorder();
    $result = ExpressSignup::run(baseInput(['password' => str_repeat('a', 257)]), makeCtx($rec, []));
    $check('validate.passwordTooLong', ($result['error'] ?? '') === 'weak_password', var_export($result, true));
    $check('validate.passwordTooLong.noCalls', count($rec->calls) === 0);

    // No active payment gateway → order_failed, and never reaches AcceptOrder.
    echo "\n--- no payment gateway ---\n";
    seed();
    Capsule::$store['tblpaymentgateways'] = [];
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 601],
    ]);
    $result = ExpressSignup::run(baseInput(['ip' => '203.0.113.77']), $ctx);
    $check('nogateway.error', ($result['error'] ?? '') === 'order_failed', var_export($result, true));
    $actions = array_map(fn($c) => $c['action'], $rec->calls);
    $check('nogateway.noAddOrder', !in_array('AddOrder', $actions, true) && !in_array('AcceptOrder', $actions, true));

    // ===================================================================
    // 6. tos_required only when a ToS URL is configured.
    // ===================================================================
    echo "\n--- terms of service ---\n";
    seed(['express_tos_url' => 'https://example.com/terms']);
    $result = ExpressSignup::run(baseInput(), makeCtx(new Recorder(), []));
    $check('tos.requiredWhenConfiguredAndMissing', ($result['error'] ?? '') === 'tos_required', var_export($result, true));

    seed(['express_tos_url' => 'https://example.com/terms']);
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 501],
        'AddOrder'          => ['result' => 'success', 'orderid' => 900, 'productids' => '777'],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    $result = ExpressSignup::run(baseInput(['tos' => true]), $ctx);
    $check('tos.acceptedWhenTicked', ($result['ok'] ?? false) === true, var_export($result, true));

    seed(['express_tos_url' => '']); // blank ToS URL → never required, even with tos omitted
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 501],
        'AddOrder'          => ['result' => 'success', 'orderid' => 900, 'productids' => '777'],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('tos.notRequiredWhenUnconfigured', ($result['ok'] ?? false) === true, var_export($result, true));

    // ===================================================================
    // 7. AddOrder failure → order_failed (client already created; no bind,
    //    no AcceptOrder, no sso).
    // ===================================================================
    echo "\n--- order failure ---\n";
    seed();
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 501],
        'AddOrder'          => ['result' => 'error', 'message' => 'Payment method not set'],
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('orderfail.error', ($result['error'] ?? '') === 'order_failed', var_export($result, true));
    $check('orderfail.stopsAfterAddOrder', $rec->events === [
        'localApi:GetClientsDetails', 'localApi:AddClient', 'localApi:AddOrder',
    ], implode(',', $rec->events));

    // ===================================================================
    // 8. SSO failure → clientarea fallback redirect, still ok:true.
    // ===================================================================
    echo "\n--- sso failure fallback ---\n";
    seed();
    seedTenant(778, 'tenant-def-456');
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 502],
        'AddOrder'          => ['result' => 'success', 'orderid' => 901, 'productids' => '778'],
        'AcceptOrder'       => ['result' => 'success'],
    ], null); // sso mint fails → null
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('ssofail.ok', ($result['ok'] ?? false) === true, var_export($result, true));
    $check('ssofail.fallbackRedirect',
        ($result['redirect'] ?? '') === 'https://shop.example.invalid/clientarea.php?action=productdetails&id=778',
        (string) ($result['redirect'] ?? 'null'));

    // ===================================================================
    // 9. Free vs monthly billingcycle selection.
    // ===================================================================
    echo "\n--- billing cycle selection ---\n";
    seed();
    seedTenant(779, 'tenant-ghi-789');
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 503],
        'AddOrder'          => ['result' => 'success', 'orderid' => 902, 'productids' => '779'],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    ExpressSignup::run(baseInput(['pid' => PID_MONTHLY]), $ctx);
    $orderCall = null;
    foreach ($rec->calls as $c) {
        if ($c['action'] === 'AddOrder') {
            $orderCall = $c['params'];
        }
    }
    $check('cycle.monthlyForRecurringProduct', ($orderCall['billingcycle'] ?? null) === ['monthly'], var_export($orderCall['billingcycle'] ?? null, true));
    $check('cycle.pidSentAsArray', ($orderCall['pid'] ?? null) === [PID_MONTHLY]);

    seed();
    seedTenant(780, 'tenant-jkl-012');
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 504],
        'AddOrder'          => ['result' => 'success', 'orderid' => 903, 'productids' => '780'],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    ExpressSignup::run(baseInput(['pid' => PID_FREE]), $ctx);
    $orderCall = null;
    foreach ($rec->calls as $c) {
        if ($c['action'] === 'AddOrder') {
            $orderCall = $c['params'];
        }
    }
    $check('cycle.freeForFreeProduct', ($orderCall['billingcycle'] ?? null) === ['free'], var_export($orderCall['billingcycle'] ?? null, true));
    $check('cycle.suppressesOrderEmails', ($orderCall['noinvoiceemail'] ?? null) === true && ($orderCall['noemail'] ?? null) === true);

    // ===================================================================
    // 10. Provisioning fix — resolveServiceId's four fallback tiers.
    // AddOrder's response shape varies across WHMCS 8.13.3 installs; each
    // case below knocks out every tier ABOVE the one under test so a
    // regression that silently falls back to the wrong tier still shows up
    // as a wrong (or missing) bound service id.
    // ===================================================================
    echo "\n--- provisioning fix: resolveServiceId tier 2 (orderid + userid) ---\n";
    seed();
    seedTenant(850, 'tenant-tier2');
    Capsule::$store['tblhosting'][] = ['id' => 850, 'orderid' => 910, 'userid' => 505, 'packageid' => PID_MONTHLY];
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 505],
        'AddOrder'          => ['result' => 'success', 'orderid' => 910], // no productids -> tier 1 misses
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('tier2.ok', ($result['ok'] ?? false) === true, var_export($result, true));
    $check('tier2.acceptCalled', in_array('localApi:AcceptOrder', $rec->events, true), implode(',', $rec->events));
    $bound850 = null;
    foreach (Capsule::$store[PromptBox::TABLE] as $row) {
        if (($row['service_id'] ?? null) === 850) {
            $bound850 = $row;
        }
    }
    $check('tier2.resolvedViaOrderIdAndUserId', $bound850 !== null);

    echo "\n--- provisioning fix: resolveServiceId tier 3 (orderid only) ---\n";
    seed();
    seedTenant(860, 'tenant-tier3');
    // userid deliberately does NOT match the new client -> tier 2 misses.
    Capsule::$store['tblhosting'][] = ['id' => 860, 'orderid' => 911, 'userid' => 0, 'packageid' => PID_MONTHLY];
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 506],
        'AddOrder'          => ['result' => 'success', 'orderid' => 911],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('tier3.ok', ($result['ok'] ?? false) === true, var_export($result, true));
    $bound860 = null;
    foreach (Capsule::$store[PromptBox::TABLE] as $row) {
        if (($row['service_id'] ?? null) === 860) {
            $bound860 = $row;
        }
    }
    $check('tier3.resolvedViaOrderIdOnly', $bound860 !== null);

    echo "\n--- provisioning fix: resolveServiceId tier 4 (userid + packageid) ---\n";
    seed();
    seedTenant(870, 'tenant-tier4');
    // orderid deliberately matches NOTHING -> tiers 2 and 3 both miss.
    Capsule::$store['tblhosting'][] = ['id' => 870, 'orderid' => 0, 'userid' => 507, 'packageid' => PID_MONTHLY];
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 507],
        'AddOrder'          => ['result' => 'success', 'orderid' => 912],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('tier4.ok', ($result['ok'] ?? false) === true, var_export($result, true));
    $bound870 = null;
    foreach (Capsule::$store[PromptBox::TABLE] as $row) {
        if (($row['service_id'] ?? null) === 870) {
            $bound870 = $row;
        }
    }
    $check('tier4.resolvedViaUserIdAndPackageId', $bound870 !== null);

    echo "\n--- provisioning fix: all four tiers miss -> still ok:true, AcceptOrder still called ---\n";
    seed();
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 508],
        'AddOrder'          => ['result' => 'success', 'orderid' => 913], // no productids; no tblhosting row anywhere
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('allmiss.ok', ($result['ok'] ?? false) === true, var_export($result, true));
    $check('allmiss.acceptStillCalled', in_array('localApi:AcceptOrder', $rec->events, true), implode(',', $rec->events));
    $check('allmiss.neverBound', !in_array('bind', $rec->events, true), implode(',', $rec->events));
    $check('allmiss.fallsBackToServicesList',
        ($result['redirect'] ?? '') === 'https://shop.example.invalid/clientarea.php?action=services',
        (string) ($result['redirect'] ?? 'null'));

    // ===================================================================
    // 11. order_failed is reserved for a genuine AddOrder failure (already
    // covered by "order failure" above) or a missing orderid — never for a
    // downstream serviceId miss (covered by case 10 above). AcceptOrder must
    // never be called without a real orderid.
    // ===================================================================
    echo "\n--- provisioning fix: AddOrder success but no orderid -> order_failed ---\n";
    seed();
    $rec = new Recorder();
    $ctx = makeCtx($rec, [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 509],
        'AddOrder'          => ['result' => 'success'], // no orderid key at all
    ]);
    $result = ExpressSignup::run(baseInput(), $ctx);
    $check('noorderid.error', ($result['error'] ?? '') === 'order_failed', var_export($result, true));
    $check('noorderid.neverAccepts', !in_array('localApi:AcceptOrder', $rec->events, true), implode(',', $rec->events));

    // ===================================================================
    // 12. Express Min Password — console setting, clamped 6..64, enforced
    // as the signup's password floor.
    // ===================================================================
    echo "\n--- express min password: Helpers reader (default + clamp) ---\n";
    seed();
    $check('minpass.defaultIsEight', \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword() === 8,
        (string) \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword());

    seed(['express_min_password' => '3']);
    $check('minpass.clampsLow', \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword() === 6,
        (string) \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword());

    seed(['express_min_password' => '999']);
    $check('minpass.clampsHigh', \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword() === 64,
        (string) \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword());

    seed(['express_min_password' => 'not-a-number']);
    $check('minpass.fallsBackOnNonNumeric', \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword() === 8,
        (string) \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword());

    echo "\n--- express min password: enforced end-to-end ---\n";
    seed(['express_min_password' => '12']);
    $result = ExpressSignup::run(baseInput(['password' => 'short11ch']), makeCtx(new Recorder(), []));
    $check('minpass.enforced.belowFloorRejected', ($result['error'] ?? '') === 'weak_password', var_export($result, true));

    seed(['express_min_password' => '12']);
    seedTenant(881, 'tenant-minpass');
    $ctx = makeCtx(new Recorder(), [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 510],
        'AddOrder'          => ['result' => 'success', 'orderid' => 920, 'productids' => '881'],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    $result = ExpressSignup::run(baseInput(['password' => 'twelvecharsX']), $ctx); // exactly 12 chars
    $check('minpass.enforced.atFloorAccepted', ($result['ok'] ?? false) === true, var_export($result, true));

    // ===================================================================
    // 13. Diagnostics — the attempt row recordExpressAttempt() writes up
    // front gets its step/note attached at exit, the console's reader
    // exposes them, and the additive schema upgrade actually adds the
    // columns on an install that predates them.
    // ===================================================================
    echo "\n--- diagnostics: attempt-row step/note recorded ---\n";
    seed();
    $check('recordAttempt.returnsPositiveIncreasingIds',
        ($idA = PromptBox::recordExpressAttempt('203.0.113.55')) > 0
        && ($idB = PromptBox::recordExpressAttempt('203.0.113.55')) > $idA,
        "idA=" . ($idA ?? 'null') . " idB=" . ($idB ?? 'null'));

    seed();
    seedTenant(890, 'tenant-diag-ok');
    $ctx = makeCtx(new Recorder(), [
        'GetClientsDetails' => ['result' => 'error', 'message' => 'Client Not Found'],
        'AddClient'         => ['result' => 'success', 'clientid' => 511],
        'AddOrder'          => ['result' => 'success', 'orderid' => 930, 'productids' => '890'],
        'AcceptOrder'       => ['result' => 'success'],
    ]);
    ExpressSignup::run(baseInput(['ip' => '203.0.113.90']), $ctx);
    $diagOk = null;
    foreach (Capsule::$store[PromptBox::ATTEMPTS_TABLE] as $row) {
        if (($row['ip'] ?? '') === '203.0.113.90') {
            $diagOk = $row;
        }
    }
    $check('diag.rowExistsOnSuccess', $diagOk !== null);
    $check('diag.stepIsOkSso', ($diagOk['step'] ?? '') === 'ok_sso', var_export($diagOk['step'] ?? null, true));
    $check('diag.noteIsEmailPrefix', ($diagOk['note'] ?? '') === 'jane.doe+promo', var_export($diagOk['note'] ?? null, true));
    $check('diag.noteNeverContainsPassword', strpos((string) ($diagOk['note'] ?? ''), 'correct-horse') === false);

    ExpressSignup::run(baseInput(['ip' => '203.0.113.91', 'email' => 'other@example.com']), makeCtx(new Recorder(), [
        'GetClientsDetails' => ['result' => 'success', 'id' => 55],
    ]));
    $diagFail = null;
    foreach (Capsule::$store[PromptBox::ATTEMPTS_TABLE] as $row) {
        if (($row['ip'] ?? '') === '203.0.113.91') {
            $diagFail = $row;
        }
    }
    $check('diag.rowExistsOnFailure', $diagFail !== null);
    $check('diag.stepIsAccountExists', ($diagFail['step'] ?? '') === 'account_exists', var_export($diagFail['step'] ?? null, true));

    $recentAttempts = PromptBox::recentExpressAttempts(20);
    $exposesStep = false;
    foreach ($recentAttempts as $r) {
        if (($r->step ?? '') === 'account_exists') {
            $exposesStep = true;
        }
    }
    $check('diag.recentExpressAttemptsExposesStepAndNote', $exposesStep);

    echo "\n--- diagnostics: additive schema upgrade adds step/note to an existing table ---\n";
    Capsule::reset();
    // Simulate a pre-v1.22.0 install: the attempts table already exists,
    // with a real row, but WITHOUT the new columns.
    Capsule::$tables[PromptBox::ATTEMPTS_TABLE] = true;
    Capsule::$store[PromptBox::ATTEMPTS_TABLE] = [
        ['id' => 1, 'ip' => '203.0.113.200', 'created_at' => '2026-01-01 00:00:00'],
    ];
    $check('upgrade.startsWithoutStepColumn', !Capsule::schema()->hasColumn(PromptBox::ATTEMPTS_TABLE, 'step'));
    $check('upgrade.startsWithoutNoteColumn', !Capsule::schema()->hasColumn(PromptBox::ATTEMPTS_TABLE, 'note'));

    PromptBox::ensureSchema();

    $check('upgrade.addsStepColumn', Capsule::schema()->hasColumn(PromptBox::ATTEMPTS_TABLE, 'step'));
    $check('upgrade.addsNoteColumn', Capsule::schema()->hasColumn(PromptBox::ATTEMPTS_TABLE, 'note'));
    $check('upgrade.preservesExistingRow',
        count(Capsule::$store[PromptBox::ATTEMPTS_TABLE]) === 1
        && (Capsule::$store[PromptBox::ATTEMPTS_TABLE][0]['ip'] ?? '') === '203.0.113.200');

    PromptBox::finishExpressAttempt(1, 'ok_sso', 'jane');
    $check('upgrade.finishExpressAttemptWorksAfterUpgrade',
        (Capsule::$store[PromptBox::ATTEMPTS_TABLE][0]['step'] ?? '') === 'ok_sso');

    PromptBox::finishExpressAttempt(0, 'whatever', 'nothing'); // id<=0 must be a silent no-op, never throw
    $check('finishExpressAttempt.noopOnNonPositiveId', true);

    // ===================================================================
    // 14. The password never appears anywhere logModuleCall recorded, across
    //     every case run above (all of which used the same test password).
    // ===================================================================
    echo "\n--- log redaction ---\n";
    $leaked = false;
    $addClientLogRequest = null;
    foreach ($GLOBALS['__logLines'] as $line) {
        $encoded = json_encode($line);
        if ($encoded !== false && strpos($encoded, 'correct-horse') !== false) {
            $leaked = true;
        }
        if ($line['action'] === 'ExpressSignup.AddClient') {
            $addClientLogRequest = $line['request'];
        }
    }
    $check('log.passwordNeverAppears', $leaked === false);
    $check('log.addClientLineHasNoPasswordKey',
        $addClientLogRequest !== null
        && !array_key_exists('password', $addClientLogRequest)
        && !array_key_exists('password2', $addClientLogRequest));
    $check('log.sawAtLeastOneAddClientLine', $addClientLogRequest !== null);

    echo "\n";
    if ($failed > 0) {
        echo "FAILED: $failed check(s).\n";
        exit(1);
    }
    echo "All checks passed.\n";
    exit(0);
}
