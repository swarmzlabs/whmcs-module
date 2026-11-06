<?php
/**
 * Swarmz WHMCS module — renewal safety net regression test (offline).
 *
 * Drives the REAL CycleSync::sweep() / claimDailyRun() / recordAnchor() and
 * the REAL Api::planRefreshBatch() (chunking, single-call fallback, pacing,
 * 429 handling, abort rules). Only the HTTP boundary is faked: a FakeApi
 * subclass scripts the answer to every post() and records the calls and
 * pauses instead of sleeping. The database is the same in-memory Capsule
 * stand-in test/existing-customer-harness.php uses (where/whereIn/first/get/
 * updateOrInsert, schema()->hasTable()/create()).
 *
 * Usage:  php test/cycle-sync-harness.php
 *
 * Like the other harnesses, this file is NOT part of the release ZIP.
 */

declare(strict_types=1);

namespace WHMCS\Database {

    class Capsule
    {
        /** @var array<string,array<int,array<string,mixed>>> table => rows */
        public static $store = [];

        /** @var array<string,bool> tables ensureSchema()/hasTable() knows about */
        public static $tables = [];

        /** @var array<string,int> */
        private static $autoInc = [];

        public static function reset(): void
        {
            self::$store = [];
            self::$tables = [];
            self::$autoInc = [];
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

        /** "tblhosting as h" → "tblhosting". */
        public static function baseName(string $table): string
        {
            $parts = preg_split('/\s+as\s+/i', trim($table));
            return $parts[0];
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
            if (is_callable($callback)) {
                $callback(new FakeBlueprint());
            }
        }
    }

    class FakeBlueprint
    {
        public function __call($name, $args)
        {
            return $this;
        }
    }

    class FakeQuery
    {
        private $table;
        private $wheres = [];
        private $joins = [];

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
                    case 'in':
                        if (!in_array($rv, $val)) {
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
            return array_values(array_filter($rows, [$this, 'rowMatches']));
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

        public function insert(array $data): bool
        {
            if (!array_key_exists('id', $data)) {
                $data['id'] = Capsule::nextId($this->table);
            }
            Capsule::$store[$this->table][] = $data;
            return true;
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
    require_once $root . '/modules/servers/swarmz/lib/CycleSync.php';

    use WHMCS\Database\Capsule;
    use WHMCS\Module\Server\Swarmz\Api;
    use WHMCS\Module\Server\Swarmz\CycleSync;
    use WHMCS\Module\Server\Swarmz\SwarmzApiException;
    use WHMCS\Module\Server\Swarmz\SwarmzTransportException;

    /** Scripted HTTP boundary: every post() pops the next scripted answer. */
    class FakeApi extends Api
    {
        /** @var array<int,array> recorded calls: path, body, timeout */
        public $calls = [];

        /** @var array<int,array|\Throwable> answers in order (a body, or an exception to throw) */
        public $script = [];

        /** @var int[] seconds the client wanted to sleep */
        public $pauses = [];

        public function post(string $path, array $body, int $timeoutSeconds = self::TIMEOUT_SECONDS): array
        {
            $this->calls[] = ['path' => $path, 'body' => $body, 'timeout' => $timeoutSeconds];
            if (empty($this->script)) {
                throw new \RuntimeException('unscripted call to ' . $path);
            }
            $next = array_shift($this->script);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return ['statusCode' => 200, 'body' => $next];
        }

        protected function pause(int $seconds): void
        {
            $this->pauses[] = $seconds;
        }
    }

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
    const KEY = 'sk_live_test_key_000000000000000000000000';
    const BASE = 'https://api.example.invalid';

    function seed(): void
    {
        Capsule::reset();
        $GLOBALS['__logLines'] = [];
        Capsule::$store['tbladdonmodules'] = [
            ['module' => 'swarmz', 'setting' => 'API Key', 'value' => KEY],
            ['module' => 'swarmz', 'setting' => 'API Base URL', 'value' => BASE],
        ];
    }

    function svc(int $id, string $tenant, string $due, string $status = 'Active'): \stdClass
    {
        return (object) ['serviceid' => $id, 'status' => $status, 'nextduedate' => $due, 'tenantid' => $tenant];
    }

    function refreshed(string $tenant): array
    {
        return ['tenant_id' => $tenant, 'external_ref' => null, 'ok' => true, 'status' => 200, 'skipped' => false,
            'result' => ['success' => true, 'included_credits' => 100, 'billing_cycle_start' => '2026-12-01T00:00:00+00:00']];
    }

    function skipped(string $tenant): array
    {
        return ['tenant_id' => $tenant, 'external_ref' => null, 'ok' => true, 'status' => 200, 'skipped' => true,
            'reason' => 'already_refreshed_for_cycle', 'result' => ['success' => true, 'skipped' => true, 'reason' => 'already_refreshed_for_cycle']];
    }

    function failedRow(string $tenant, int $status, string $error): array
    {
        return ['tenant_id' => $tenant, 'external_ref' => null, 'ok' => false, 'status' => $status, 'error' => $error];
    }

    function batchOk(array $rows): array
    {
        return ['ok' => true, 'results' => $rows];
    }

    function apiErr(int $status, string $code, array $body = []): SwarmzApiException
    {
        return new SwarmzApiException($code, $status, $code, $body + ['error' => $code]);
    }

    function ctx(FakeApi $api, ?callable $serviceKey = null): array
    {
        return [
            'apiKey'     => KEY,
            'baseUrl'    => BASE,
            'api'        => function (string $key, string $base) use ($api) {
                return $api;
            },
            'serviceKey' => $serviceKey ?? function (int $serviceId): string {
                return '';
            },
        ];
    }

    function anchors(): array
    {
        $out = [];
        foreach (Capsule::$store[CycleSync::TABLE] ?? [] as $row) {
            $out[(int) $row['serviceid']] = $row;
        }
        ksort($out);
        return $out;
    }

    function logActions(string $action): array
    {
        return array_values(array_filter($GLOBALS['__logLines'], static function ($l) use ($action) {
            return $l['action'] === $action;
        }));
    }

    function threeServices(): array
    {
        return [
            svc(101, 'tenant-101', '2026-12-01'),
            svc(102, 'tenant-102', '2026-12-05'),
            svc(103, 'tenant-103', '2026-12-09'),
        ];
    }

    // ===================================================================
    // 1. Delta: first pass sends everything, records anchors; second pass
    //    sends nothing; a moved due date sends only that service.
    // ===================================================================
    echo "--- delta ---\n";
    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script[] = batchOk([refreshed('tenant-101'), skipped('tenant-102'), skipped('tenant-103')]);
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('delta.firstPassOneBatchRequest', count($api->calls) === 1 && $api->calls[0]['path'] === '/functions/v1/platform-plan-refresh', var_export($api->calls, true));
    $check('delta.firstPassSendsAllThree', count($api->calls[0]['body']['services'] ?? []) === 3);
    $check('delta.batchItemShape', ($api->calls[0]['body']['services'][1] ?? null) === ['tenant_id' => 'tenant-102', 'cycle_anchor' => '2026-12-05'], var_export($api->calls[0]['body'], true));
    $check('delta.batchTimeout', $api->calls[0]['timeout'] === Api::PLAN_REFRESH_BATCH_TIMEOUT_SECONDS);
    $check('delta.summary', $s['services'] === 3 && $s['sent'] === 3 && $s['requests'] === 1 && $s['refreshed'] === 1 && $s['skipped'] === 2 && $s['failed'] === 0 && $s['mode'] === 'batch', var_export($s, true));
    $a = anchors();
    $check('delta.anchorsRecorded', count($a) === 3 && $a[101]['last_anchor'] === '2026-12-01' && $a[103]['last_anchor'] === '2026-12-09', var_export($a, true));
    $check('delta.anchorResultKept', $a[101]['last_result'] === 'refreshed' && $a[102]['last_result'] === 'skipped');
    $check('delta.recoveredLogged', count(logActions('DailyCronJob.RenewalRecovered')) === 1 && (logActions('DailyCronJob.RenewalRecovered')[0]['request']['serviceid'] ?? 0) === 101);
    $check('delta.sweepSummaryLogged', count(logActions('DailyCronJob.RenewalSweep')) === 1);

    // Second pass, nothing moved: no request at all.
    $api = new FakeApi(KEY, BASE);
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('delta.secondPassNoRequest', count($api->calls) === 0 && $s['unchanged'] === 3 && $s['sent'] === 0, var_export($s, true));
    $check('delta.secondPassStillLogsSummary', count(logActions('DailyCronJob.RenewalSweep')) === 2);

    // One due date moved: only that service goes.
    $api = new FakeApi(KEY, BASE);
    $api->script[] = batchOk([refreshed('tenant-102')]);
    $moved = threeServices();
    $moved[1] = svc(102, 'tenant-102', '2027-01-05');
    $s = CycleSync::sweep($moved, ctx($api));
    $check('delta.onlyMovedServiceSent', count($api->calls) === 1 && ($api->calls[0]['body']['services'] ?? null) === [['tenant_id' => 'tenant-102', 'cycle_anchor' => '2027-01-05']], var_export($api->calls, true));
    $check('delta.movedAnchorUpdated', anchors()[102]['last_anchor'] === '2027-01-05' && $s['unchanged'] === 2 && $s['refreshed'] === 1);

    // A failed item is not recorded and is re-sent next pass.
    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script[] = batchOk([refreshed('tenant-101'), failedRow('tenant-102', 404, 'tenant_not_found'), failedRow('tenant-103', 410, 'terminated')]);
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('delta.failedNotRecorded', count(anchors()) === 1 && isset(anchors()[101]) && $s['failed'] === 2, var_export([$s, anchors()], true));
    $api = new FakeApi(KEY, BASE);
    $api->script[] = batchOk([skipped('tenant-102'), skipped('tenant-103')]);
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('delta.failedResentNextPass', count($api->calls) === 1 && count($api->calls[0]['body']['services']) === 2 && $s['unchanged'] === 1, var_export($api->calls, true));

    // Inactive services, blank/zero due dates and missing tenants never go.
    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script[] = batchOk([skipped('tenant-101')]);
    $s = CycleSync::sweep([
        svc(101, 'tenant-101', '2026-12-01'),
        svc(102, 'tenant-102', '2026-12-05', 'Suspended'),
        svc(103, 'tenant-103', '0000-00-00'),
        svc(104, 'tenant-104', ''),
        svc(105, '', '2026-12-09'),
        svc(0, 'tenant-0', '2026-12-09'),
    ], ctx($api));
    $check('delta.filtersMatchTheOldSweep', $s['services'] === 1 && count($api->calls[0]['body']['services']) === 1, var_export($s, true));

    // Nothing to send → no request, one summary line, no exception.
    seed();
    $api = new FakeApi(KEY, BASE);
    $s = CycleSync::sweep([], ctx($api));
    $check('delta.emptyInputIsQuiet', count($api->calls) === 0 && $s['services'] === 0 && count(logActions('DailyCronJob.RenewalSweep')) === 1);

    // ===================================================================
    // 2. Chunking at the platform's cap.
    // ===================================================================
    echo "\n--- chunking ---\n";
    seed();
    $many = [];
    for ($i = 1; $i <= 450; $i++) {
        $many[] = svc(1000 + $i, 'tenant-' . $i, '2026-12-01');
    }
    $api = new FakeApi(KEY, BASE);
    $rows = static function (int $n): array {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = skipped('x');
        }
        return $out;
    };
    $api->script = [batchOk($rows(200)), batchOk($rows(200)), batchOk($rows(50))];
    $s = CycleSync::sweep($many, ctx($api));
    $sizes = array_map(static function ($c) {
        return count($c['body']['services']);
    }, $api->calls);
    $check('chunk.threeRequests', $sizes === [200, 200, 50] && $s['requests'] === 3, var_export($sizes, true));
    $check('chunk.everyServiceRecorded', count(anchors()) === 450 && $s['skipped'] === 450);
    $check('chunk.portalRidesAlong', array_key_exists('billing_portal', $api->calls[0]['body']) === (Api::billingPortal() !== null));

    // ===================================================================
    // 3. Fallback: a platform without the batch body.
    // ===================================================================
    echo "\n--- fallback ---\n";
    foreach ([[400, 'missing_fields'], [404, 'not_found']] as [$status, $code]) {
        seed();
        $api = new FakeApi(KEY, BASE);
        $api->script[] = apiErr($status, $code, ['reason' => 'tenant_id or external_ref required']);
        for ($i = 1; $i <= 12; $i++) {
            $api->script[] = ['ok' => true, 'result' => $i === 1
                ? ['success' => true, 'included_credits' => 100]
                : ['success' => true, 'skipped' => true, 'reason' => 'already_refreshed_for_cycle']];
        }
        $services = [];
        for ($i = 1; $i <= 12; $i++) {
            $services[] = svc(200 + $i, 'tenant-' . $i, '2026-12-01');
        }
        $s = CycleSync::sweep($services, ctx($api));
        $check("fallback.$status.oneBatchThenSingles", count($api->calls) === 13 && $s['mode'] === 'single' && $s['requests'] === 13, var_export([count($api->calls), $s], true));
        $check("fallback.$status.singleBodyShape", ($api->calls[1]['body']['tenant_id'] ?? '') === 'tenant-1' && ($api->calls[1]['body']['cycle_anchor'] ?? '') === '2026-12-01' && !isset($api->calls[1]['body']['services']), var_export($api->calls[1]['body'], true));
        $check("fallback.$status.singleTimeoutIsDefault", $api->calls[1]['timeout'] === Api::TIMEOUT_SECONDS);
        $check("fallback.$status.pacedAfterTenCalls", $api->pauses === [Api::PLAN_REFRESH_PACE_SECONDS], var_export($api->pauses, true));
        $check("fallback.$status.resultsRecorded", count(anchors()) === 12 && $s['refreshed'] === 1 && $s['skipped'] === 11, var_export([$s, count(anchors())], true));
    }

    // A definitive per-item error in single mode is a failed result; the run goes on.
    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script = [
        apiErr(404, 'not_found'),
        apiErr(404, 'tenant_not_found'),
        ['ok' => true, 'result' => ['success' => true, 'skipped' => true]],
        apiErr(500, 'refresh_failed', ['reason' => 'boom']),
    ];
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('fallback.itemErrorKeepsGoing', count($api->calls) === 4 && $s['failed'] === 2 && $s['skipped'] === 1 && !$s['aborted'], var_export($s, true));
    $check('fallback.itemErrorNotRecorded', array_keys(anchors()) === [102]);

    // ===================================================================
    // 4. 429: wait once (retry_after, capped), retry once; a second 429 ends the run.
    // ===================================================================
    echo "\n--- rate limit ---\n";
    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script = [apiErr(429, 'rate_limited', ['retry_after_seconds' => 7]), batchOk([skipped('tenant-101'), skipped('tenant-102'), skipped('tenant-103')])];
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('rate.waitsRetryAfterThenRetries', $api->pauses === [7] && count($api->calls) === 2 && $s['rate_limited'] === 1 && $s['skipped'] === 3 && !$s['aborted'], var_export([$api->pauses, $s], true));

    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script = [apiErr(429, 'rate_limited', ['retry_after_seconds' => 7]), apiErr(429, 'rate_limited', ['retry_after_seconds' => 59]), batchOk([])];
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('rate.second429EndsTheRun', $api->pauses === [7] && count($api->calls) === 2 && $s['aborted'] === true && $s['failed'] === 3 && count(anchors()) === 0, var_export([$api->pauses, count($api->calls), $s], true));

    $out = (function () {
        $api = new FakeApi(KEY, BASE);
        $api->script = [apiErr(429, 'rate_limited', ['retry_after_seconds' => 900]), batchOk([skipped('a')])];
        $api->planRefreshBatch([['tenant_id' => 'a', 'cycle_anchor' => '2026-12-01']]);
        $capped = $api->pauses;
        $api = new FakeApi(KEY, BASE);
        $api->script = [apiErr(429, 'rate_limited'), batchOk([skipped('a')])];
        $api->planRefreshBatch([['tenant_id' => 'a', 'cycle_anchor' => '2026-12-01']]);
        return [$capped, $api->pauses];
    })();
    $check('rate.waitIsBounded', $out === [[Api::RATE_LIMIT_MAX_WAIT_SECONDS], [60]], var_export($out, true));

    // 429 in single mode uses the same one wait.
    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script = [
        apiErr(404, 'not_found'),
        apiErr(429, 'rate_limited', ['retry_after_seconds' => 3]),
        ['ok' => true, 'result' => ['success' => true, 'skipped' => true]],
        apiErr(429, 'rate_limited', ['retry_after_seconds' => 3]),
        ['ok' => true, 'result' => ['success' => true, 'skipped' => true]],
    ];
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('rate.singleModeSecond429Ends', $api->pauses === [3] && count($api->calls) === 4 && $s['aborted'] && $s['skipped'] === 1 && $s['failed'] === 2, var_export([$api->pauses, count($api->calls), $s], true));

    // A transport fault or gateway status ends the run; nothing recorded for the rest.
    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script = [new SwarmzTransportException('Network error', 0, 'transport_error')];
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('abort.transportFault', $s['aborted'] && $s['failed'] === 3 && count(anchors()) === 0 && count($api->calls) === 1, var_export($s, true));
    seed();
    $api = new FakeApi(KEY, BASE);
    $api->script = [apiErr(503, 'service_unavailable')];
    $s = CycleSync::sweep(threeServices(), ctx($api));
    $check('abort.gatewayStatus', $s['aborted'] && $s['failed'] === 3 && count(anchors()) === 0, var_export($s, true));

    // ===================================================================
    // 5. Per-service keys → one batch per key.
    // ===================================================================
    echo "\n--- keys ---\n";
    seed();
    $apiA = new FakeApi('sk_live_key_a_0000000000000000000000000000', BASE);
    $apiB = new FakeApi(KEY, BASE);
    $apiA->script[] = batchOk([skipped('tenant-101')]);
    $apiB->script[] = batchOk([skipped('tenant-102'), skipped('tenant-103')]);
    $seenKeys = [];
    $s = CycleSync::sweep(threeServices(), [
        'apiKey' => KEY, 'baseUrl' => BASE,
        'api' => function (string $key, string $base) use ($apiA, $apiB, &$seenKeys) {
            $seenKeys[] = $key;
            return $key === KEY ? $apiB : $apiA;
        },
        'serviceKey' => function (int $serviceId): string {
            return $serviceId === 101 ? 'sk_live_key_a_0000000000000000000000000000' : '';
        },
    ]);
    $check('keys.oneBatchPerKey', count($apiA->calls) === 1 && count($apiB->calls) === 1 && count($seenKeys) === 2 && $s['requests'] === 2, var_export([$seenKeys, $s], true));
    $check('keys.allRecorded', count(anchors()) === 3);

    // ===================================================================
    // 6. Once a day.
    // ===================================================================
    echo "\n--- daily guard ---\n";
    seed();
    $t0 = 1_800_000_000;
    $check('guard.firstRunClaims', CycleSync::claimDailyRun($t0) === true);
    $stamp = '';
    foreach (Capsule::$store['tbladdonmodules'] as $row) {
        if ($row['setting'] === CycleSync::LAST_RUN_SETTING) {
            $stamp = $row['value'];
        }
    }
    $check('guard.stampWrittenAsIso', $stamp === gmdate('c', $t0), $stamp);
    $check('guard.secondRunWithin20hIsNoOp', CycleSync::claimDailyRun($t0 + 2 * 60) === false);
    $check('guard.stillNoOpAt19h', CycleSync::claimDailyRun($t0 + 19 * 3600) === false);
    $check('guard.runsAgainAfter20h', CycleSync::claimDailyRun($t0 + 20 * 3600 + 1) === true);
    $check('guard.stampMovesForward', (function () use ($t0) {
        foreach (Capsule::$store['tbladdonmodules'] as $row) {
            if ($row['setting'] === CycleSync::LAST_RUN_SETTING) {
                return $row['value'] === gmdate('c', $t0 + 20 * 3600 + 1);
            }
        }
        return false;
    })());
    $check('guard.onlyOneStampRow', count(array_filter(Capsule::$store['tbladdonmodules'], static function ($r) {
        return $r['setting'] === CycleSync::LAST_RUN_SETTING;
    })) === 1);
    // A stamp from the future (clock moved back) or garbage never blocks the pass.
    foreach (Capsule::$store['tbladdonmodules'] as $i => $row) {
        if ($row['setting'] === CycleSync::LAST_RUN_SETTING) {
            Capsule::$store['tbladdonmodules'][$i]['value'] = gmdate('c', $t0 + 10 * 86400);
        }
    }
    $check('guard.futureStampRuns', CycleSync::claimDailyRun($t0) === true);
    foreach (Capsule::$store['tbladdonmodules'] as $i => $row) {
        if ($row['setting'] === CycleSync::LAST_RUN_SETTING) {
            Capsule::$store['tbladdonmodules'][$i]['value'] = 'not a date';
        }
    }
    $check('guard.garbageStampRuns', CycleSync::claimDailyRun($t0) === true);
    $check('guard.otherSettingsUntouched', Capsule::$store['tbladdonmodules'][0]['value'] === KEY);

    // ===================================================================
    // 7. recordAnchor (the InvoicePaid path) and robustness.
    // ===================================================================
    echo "\n--- anchors ---\n";
    seed();
    CycleSync::recordAnchor(7, '2026-12-01', 'refreshed');
    CycleSync::recordAnchor(7, '2027-01-01', 'skipped');
    CycleSync::recordAnchor(8, 'nope', 'refreshed');
    CycleSync::recordAnchor(0, '2026-12-01', 'refreshed');
    $a = anchors();
    $check('anchor.upsertKeepsOneRowPerService', count($a) === 1 && $a[7]['last_anchor'] === '2027-01-01' && $a[7]['last_result'] === 'skipped', var_export($a, true));
    $check('anchor.mapReadsBack', CycleSync::anchorMap([7, 8, 9]) === [7 => '2027-01-01']);
    $check('anchor.schemaCreatedLazily', Capsule::schema()->hasTable(CycleSync::TABLE));

    // An API client that throws out of planRefreshBatch is contained by the sweep.
    seed();
    $api = new FakeApi(KEY, BASE); // nothing scripted → RuntimeException from the fake
    $threw = false;
    try {
        $s = CycleSync::sweep(threeServices(), ctx($api));
    } catch (\Throwable $e) {
        $threw = true;
    }
    $check('robust.throwingClientContained', $threw === false && $s['failed'] === 3 && count(logActions('DailyCronJob.RenewalSweep.Failed')) === 1, var_export($s ?? null, true));

    // Keys never appear in the recorded log lines.
    $leak = false;
    foreach ($GLOBALS['__logLines'] as $line) {
        if (strpos(json_encode($line) ?: '', 'sk_live_') !== false) {
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
