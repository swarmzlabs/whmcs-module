<?php
/**
 * Swarmz WHMCS Module - Renewal safety net (v1.27.0).
 *
 * The daily cron re-issues an idempotent plan refresh for the services whose
 * billing cycle may have moved without the InvoicePaid hook landing. Before
 * 1.27.0 that pass sent EVERY active service's due date, one request each,
 * every night — the platform answered "already refreshed" for nearly all of
 * them, and a host with a few hundred customers ran into its per-minute
 * limit, so the services at the end of the list were silently left out.
 *
 * Now the module remembers, per service, the last due date the platform
 * accepted (a 2xx answer, refreshed or skipped) in a small module-owned
 * table, and the nightly pass sends only the services whose current due date
 * differs — in ONE batch request (Api::planRefreshBatch), chunked and paced,
 * with a single-call fallback for platform deployments that predate the
 * batch body. Steady state: a handful of calls per host per night.
 *
 * The InvoicePaid hook stays the primary signal; it records its anchor here
 * too, so a renewal that landed through it is not re-sent at night.
 *
 * Everything here is best-effort: a failure skips the service (or the pass)
 * and never throws back into the WHMCS cron.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Server\Swarmz;

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

use WHMCS\Database\Capsule;

class CycleSync
{
    /** Per-service "last accepted anchor" table (WHMCS convention: mod_<module>_…). */
    const TABLE = 'mod_swarmz_cycle';

    /**
     * tbladdonmodules setting that records when the daily pass last ran
     * (ISO-8601, UTC). Same storage the Updater uses for its check cache.
     */
    const LAST_RUN_SETTING = 'Daily Cron Last Run';

    /** A second DailyCronJob inside this window is a no-op (20 hours). */
    const DAILY_GUARD_SECONDS = 72000;

    // ---------------------------------------------------------------- schema

    /**
     * Create the anchors table if missing. Idempotent and additive (guarded
     * by hasTable; never recreates or alters). Called from the addon's
     * activate/upgrade and lazily before every write.
     */
    public static function ensureSchema(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, function ($table) {
                    $table->unsignedInteger('serviceid')->primary();
                    $table->string('last_anchor', 10);
                    $table->string('last_result', 16)->default('');
                    $table->dateTime('last_ok_at');
                });
            }
        } catch (\Throwable $e) {
            // Schema plumbing is best-effort — a failure surfaces on first use.
        }
    }

    /**
     * serviceid => last accepted anchor ("YYYY-MM-DD") for the given services;
     * services never accepted are absent. Empty on any read failure (which
     * makes the pass send everything — the safe direction).
     *
     * @param int[] $serviceIds
     * @return array<int,string>
     */
    public static function anchorMap(array $serviceIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $serviceIds)));
        if (empty($ids)) {
            return [];
        }
        try {
            if (!Capsule::schema()->hasTable(self::TABLE)) {
                return [];
            }
            $out = [];
            $rows = Capsule::table(self::TABLE)->whereIn('serviceid', $ids)->get(['serviceid', 'last_anchor']);
            foreach ($rows as $r) {
                $out[(int) $r->serviceid] = (string) $r->last_anchor;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Remember that the platform accepted $anchor for this service. Called
     * after any 2xx plan-refresh answer (refreshed or skipped) from the
     * nightly pass and from InvoicePaid. Never throws.
     *
     * @param int    $serviceId
     * @param string $anchor  "YYYY-MM-DD"
     * @param string $result  'refreshed' | 'skipped'
     */
    public static function recordAnchor(int $serviceId, string $anchor, string $result = 'skipped'): void
    {
        if ($serviceId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
            return;
        }
        try {
            self::ensureSchema();
            Capsule::table(self::TABLE)->updateOrInsert(
                ['serviceid' => $serviceId],
                [
                    'last_anchor' => $anchor,
                    'last_result' => substr($result, 0, 16),
                    'last_ok_at'  => date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            // best-effort — the service is simply re-sent next time
        }
    }

    // ------------------------------------------------------------ once a day

    /**
     * Claim today's daily pass. True when the pass may run (and the stamp is
     * written); false when one already ran inside DAILY_GUARD_SECONDS. Some
     * WHMCS installs fire DailyCronJob more than once a day (two cron
     * schedules, a manual re-run), and every extra pass repeated every
     * platform call. Fail-open: an unreadable or unwritable stamp runs the
     * pass as before.
     *
     * @param int|null $now Unix time (tests); null = now.
     */
    public static function claimDailyRun(?int $now = null): bool
    {
        $now = $now ?? time();
        try {
            $raw = trim((string) Helpers::addonSetting(self::LAST_RUN_SETTING, ''));
            if ($raw !== '') {
                $last = strtotime($raw);
                if ($last !== false && $last <= $now && ($now - $last) < self::DAILY_GUARD_SECONDS) {
                    return false;
                }
            }
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => Helpers::ADDON_MODULE, 'setting' => self::LAST_RUN_SETTING],
                ['value' => gmdate('c', $now)]
            );
        } catch (\Throwable $e) {
            // Guard is best-effort; run the pass.
        }
        return true;
    }

    // ------------------------------------------------------------- the sweep

    /**
     * The nightly renewal safety net.
     *
     * For every ACTIVE service with a real next-due-date whose date differs
     * from the last anchor the platform accepted, send one plan refresh
     * anchored at that date — all of them in one batch request per API key
     * (chunked at the platform's cap, paced, 429-aware, single-call fallback
     * for an older platform). A 2xx answer records the anchor; a refresh that
     * actually rolled the cycle is logged as a recovered renewal, as before.
     * Anchors come strictly from the due date (never today()), so a cycle can
     * never roll early; a blank due date is skipped.
     *
     * @param array<int,\stdClass> $services Rows with serviceid, status,
     *                                       nextduedate, tenantid (the cron's
     *                                       gather query).
     * @param array $ctx Injectable boundaries (tests), all optional:
     *   'apiKey'     => string  host-wide key (default: the Reseller Console setting)
     *   'baseUrl'    => string  API base (default: the Reseller Console setting)
     *   'api'        => function(string $key, string $baseUrl): Api
     *   'serviceKey' => function(int $serviceId): string  per-service key ('' = none)
     * @return array The summary that is also written to the Module Log.
     */
    public static function sweep(array $services, array $ctx = []): array
    {
        $summary = [
            'services' => 0, 'unchanged' => 0, 'sent' => 0, 'requests' => 0, 'mode' => 'batch',
            'refreshed' => 0, 'skipped' => 0, 'failed' => 0, 'rate_limited' => 0, 'aborted' => false,
        ];

        $hostKey = array_key_exists('apiKey', $ctx)
            ? trim((string) $ctx['apiKey'])
            : trim((string) Helpers::addonSetting('API Key', ''));
        $baseUrl = array_key_exists('baseUrl', $ctx)
            ? trim((string) $ctx['baseUrl'])
            : trim((string) Helpers::addonSetting('API Base URL', ''));
        $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : Helpers::DEFAULT_API_BASE_URL;
        $apiFactory = isset($ctx['api']) && is_callable($ctx['api'])
            ? $ctx['api']
            : static function (string $key, string $base): Api {
                return new Api($key, $base);
            };
        $serviceKey = isset($ctx['serviceKey']) && is_callable($ctx['serviceKey'])
            ? $ctx['serviceKey']
            : static function (int $serviceId): string {
                return Helpers::resolveServiceServerKey($serviceId);
            };

        // 1. Candidates: active, provisioned, with a usable due date and a key.
        $candidates = [];
        foreach ($services as $s) {
            if (strtolower((string) ($s->status ?? '')) !== 'active') {
                continue; // only active (paid-through) services roll a cycle
            }
            $serviceId = (int) ($s->serviceid ?? 0);
            $tenant = trim((string) ($s->tenantid ?? ''));
            if ($serviceId <= 0 || $tenant === '') {
                continue;
            }
            $due = trim((string) ($s->nextduedate ?? ''));
            if ($due === '' || strpos($due, '0000-00-00') === 0 || !preg_match('/^\d{4}-\d{2}-\d{2}/', $due)) {
                continue; // blank/zero due date → never today()
            }
            $key = $hostKey;
            try {
                $perService = trim((string) $serviceKey($serviceId));
            } catch (\Throwable $e) {
                $perService = '';
            }
            if ($perService !== '') {
                $key = $perService;
            }
            if ($key === '') {
                continue;
            }
            $candidates[$serviceId] = ['tenant' => $tenant, 'anchor' => substr($due, 0, 10), 'key' => $key];
        }
        $summary['services'] = count($candidates);
        if (empty($candidates)) {
            self::log('DailyCronJob.RenewalSweep', [], $summary);
            return $summary;
        }

        // 2. Delta: drop every service whose due date the platform already accepted.
        $known = self::anchorMap(array_keys($candidates));
        foreach ($candidates as $serviceId => $c) {
            if (isset($known[$serviceId]) && $known[$serviceId] === $c['anchor']) {
                unset($candidates[$serviceId]);
                $summary['unchanged']++;
            }
        }
        if (empty($candidates)) {
            self::log('DailyCronJob.RenewalSweep', [], $summary);
            return $summary;
        }

        // 3. One batch per API key (nearly always one key per host).
        $groups = [];
        foreach ($candidates as $serviceId => $c) {
            $groups[$c['key']][$serviceId] = $c;
        }
        foreach ($groups as $key => $group) {
            $serviceIds = array_keys($group);
            $items = [];
            foreach ($group as $c) {
                $items[] = ['tenant_id' => $c['tenant'], 'cycle_anchor' => $c['anchor']];
            }
            $summary['sent'] += count($items);
            try {
                $api = $apiFactory((string) $key, $baseUrl);
                $out = $api->planRefreshBatch($items);
            } catch (\Throwable $e) {
                $summary['failed'] += count($items);
                self::log('DailyCronJob.RenewalSweep.Failed', ['services' => count($items)], ['error' => $e->getMessage()]);
                continue;
            }
            $summary['requests'] += (int) ($out['requests'] ?? 0);
            $summary['rate_limited'] += (int) ($out['rate_limited'] ?? 0);
            if (($out['mode'] ?? 'batch') === 'single') {
                $summary['mode'] = 'single';
            }
            if (!empty($out['aborted'])) {
                $summary['aborted'] = true;
            }
            foreach ($serviceIds as $i => $serviceId) {
                $r = $out['results'][$i] ?? null;
                if (!is_array($r) || empty($r['ok'])) {
                    $summary['failed']++;
                    continue; // not recorded → re-sent tomorrow
                }
                $c = $group[$serviceId];
                if (!empty($r['skipped'])) {
                    $summary['skipped']++;
                    self::recordAnchor($serviceId, $c['anchor'], 'skipped');
                    continue;
                }
                // A non-skip means a renewal had been missed and was just healed —
                // log it so the host can see the recovery (skips are silent/normal).
                $summary['refreshed']++;
                self::recordAnchor($serviceId, $c['anchor'], 'refreshed');
                self::log(
                    'DailyCronJob.RenewalRecovered',
                    ['serviceid' => $serviceId, 'tenant_id' => $c['tenant'], 'cycle_anchor' => $c['anchor']],
                    is_array($r['result'] ?? null) ? $r['result'] : [],
                    $api->maskedKey()
                );
            }
        }

        self::log('DailyCronJob.RenewalSweep', [], $summary);
        return $summary;
    }

    /** Module-log wrapper (mirrors _swarmz_hookLog redaction). */
    private static function log(string $action, $request, $response, string $maskedKey = ''): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        $replaceVars = ['sk_live_', 'sk_test_', 'Bearer '];
        if ($maskedKey !== '') {
            $replaceVars[] = $maskedKey;
        }
        try {
            logModuleCall('swarmz', $action, $request, $response, $response, $replaceVars);
        } catch (\Throwable $e) {
            // Logging must never break the cron.
        }
    }
}
