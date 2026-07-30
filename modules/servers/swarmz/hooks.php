<?php
/**
 * Swarmz WHMCS Module - Action Hooks.
 *
 * Cross-product behaviour that lives outside the per-service provisioning
 * lifecycle:
 *
 *   - DailyCronJob : a once-a-day reconciliation pass. Refreshes usage for
 *     every provisioned swarmz service (so the client area shows live credit /
 *     cloud stats without waiting for a client-area page load) and reconciles
 *     service status against swarmz (pull externally suspended / terminated
 *     workspaces back into WHMCS).
 *
 *   - InvoicePaid : treat a paid renewal invoice as a billing-cycle boundary
 *     and roll the swarmz credit cycle (reset monthly credits + apply rollover)
 *     via /platform-plan-refresh, anchored at the service's next-due-date.
 *
 * All work is best-effort and never throws back into WHMCS: a swarmz API blip
 * must not break the WHMCS cron or invoice flow.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/lib/Exceptions.php';
require_once __DIR__ . '/lib/Api.php';
require_once __DIR__ . '/lib/Helpers.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Swarmz\Api;
use WHMCS\Module\Server\Swarmz\Helpers;
use WHMCS\Module\Server\Swarmz\SwarmzApiException;

/**
 * Daily reconciliation cron.
 *
 * Runs inside WHMCS's own daily cron. Two jobs, both keyed off the swarmz
 * tenant ids the provisioning module stores on each service:
 *
 *   1. Usage refresh — ONE account-wide /platform-usage call (the same single
 *      round-trip the admin Console uses) primes swarmz's usage view and lets
 *      us cache the per-tenant numbers onto each service, so the client area is
 *      instant and correct even before its first lazy UsageUpdate of the day.
 *
 *   2. Status reconcile — pull swarmz-side status back into WHMCS. A workspace
 *      a swarmz operator suspended/terminated out-of-band should be reflected
 *      in WHMCS so the two never drift. We detect terminated workspaces (the
 *      tenant resolves but is gone / 410) and suspend the matching WHMCS
 *      service so the client loses access; an admin can investigate.
 *
 * NOTE on platform-meter-cron: the plan calls for nudging swarmz's
 * `platform-meter-cron`. That endpoint authenticates with an `X-Cron-Secret`
 * header (NOT the sk_live_ platform key this module holds), so it is NOT
 * callable with host credentials — swarmz schedules it itself via pg_cron. We
 * therefore only invoke it when the host has explicitly stored a cron secret in
 * the Reseller Console addon ("Meter Cron Secret"); otherwise we skip it
 * silently and rely on the usage refresh above. See the buildout report.
 */
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        _swarmz_cron_reconcile();
    } catch (\Throwable $e) {
        // A reconciliation failure must never break the WHMCS daily cron.
        _swarmz_hookLog('DailyCronJob.Error', [], ['error' => $e->getMessage()]);
    }
});

/**
 * Renewal → roll the credit cycle.
 *
 * WHMCS has no server-module "renewal" hook, so we hook InvoicePaid: when an
 * invoice that contains a swarmz service line is paid, that service has just
 * renewed into a new billing cycle. We refresh each such service's plan,
 * anchored at its (freshly-advanced) next-due-date, so monthly credits reset
 * and rollover is applied exactly at the cycle boundary the host bills on.
 *
 * Idempotent per (tenant, cycle_anchor) on the swarmz side, so a re-fired
 * InvoicePaid (or an overlap with ChangePackage) is a safe no-op.
 *
 * @param array $vars { invoiceid: int, ... }
 */
add_hook('InvoicePaid', 1, function ($vars) {
    $invoiceId = (int) ($vars['invoiceid'] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }
    try {
        _swarmz_cron_refreshInvoiceServices($invoiceId);
    } catch (\Throwable $e) {
        _swarmz_hookLog('InvoicePaid.Error', ['invoiceid' => $invoiceId], ['error' => $e->getMessage()]);
    }
    // Credit packs (v1.11.0): grant mapped Product-Addon credit packs on this
    // paid invoice. Idempotent per (invoice, hosting-addon) — the platform
    // dedupes on the key — and internally best-effort, so a pack grant can
    // never break the invoice flow or the plan refresh above.
    try {
        Helpers::grantCreditPacksOnInvoice($invoiceId);
    } catch (\Throwable $e) {
        _swarmz_hookLog('InvoicePaid.CreditPacks.Error', ['invoiceid' => $invoiceId], ['error' => $e->getMessage()]);
    }
});

/**
 * Invoice-less credit packs → grant on activation (v1.11.1).
 *
 * A FREE-cycle Product Addon (or one an admin adds by hand) never produces an
 * invoice, so the payment-triggered grant above can never fire for it. When a
 * client addon is activated and its instance has NO invoice line at all, grant
 * the mapped pack immediately with the activation idempotency key
 * (`whmcs-ha<id>-act`) — exactly once per instance, platform-deduped, so
 * repeat activations can't double-grant. Instances WITH an invoice line are
 * left strictly to the payment path. The daily sweep re-covers this hook (same
 * key) if the API blipped at activation time.
 *
 * @param array $vars { id: int (tblhostingaddons.id), ... }
 */
add_hook('AddonActivated', 1, function ($vars) {
    $haId = (int) ($vars['id'] ?? 0);
    if ($haId <= 0) {
        return;
    }
    try {
        $map = Helpers::creditPackMap();
        if (empty($map)) {
            return;
        }
        $ha = Capsule::table('tblhostingaddons')->where('id', $haId)->first(['id', 'addonid']);
        if (!$ha || !isset($map[(int) $ha->addonid])) {
            return; // not a mapped pack
        }
        $hasInvoiceLine = Capsule::table('tblinvoiceitems')
            ->where('type', 'Addon')
            ->where('relid', $haId)
            ->exists();
        if ($hasInvoiceLine) {
            return; // the payment path owns invoiced packs
        }
        Helpers::grantOneCreditPack(0, $haId, $map);
    } catch (\Throwable $e) {
        _swarmz_hookLog('AddonActivated.CreditPacks.Error', ['hostingaddon' => $haId], ['error' => $e->getMessage()]);
    }
});

// =========================================================================
// Internal helpers (file-local). Prefixed to avoid colliding with the server
// module's own _swarmz_* helpers (different file, no shared scope at runtime,
// but we keep names distinct for clarity).
// =========================================================================

/**
 * The core daily reconciliation. Gathers every provisioned swarmz service,
 * does one account-wide usage call, caches per-tenant usage, and reconciles
 * terminated workspaces back to WHMCS.
 */
function _swarmz_cron_reconcile(): void
{
    $services = _swarmz_cron_gatherServices();
    if (empty($services)) {
        return; // Nothing provisioned — nothing to do.
    }

    // Build an API client from the addon-stored key/base-url (the cron has no
    // per-service $params bag with a server Password, so we read the host-wide
    // key the Reseller Console stores). If no key is configured, bail quietly.
    $apiKey = trim((string) Helpers::addonSetting('API Key', ''));
    if ($apiKey === '') {
        _swarmz_hookLog('DailyCronJob.NoKey', [], ['note' => 'No API key configured in the Reseller Console addon; skipping.']);
        return;
    }
    $baseUrl = trim((string) Helpers::addonSetting('API Base URL', ''));
    $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : Helpers::DEFAULT_API_BASE_URL;

    $api = new Api($apiKey, $baseUrl);

    // ── 1. Usage refresh: ONE account-wide call, map by workspace_id. ──────
    $usageMap = [];
    try {
        $res = $api->postPlatform('platform-usage', ['period' => 'current_month']);
        $usage = (isset($res['body']['usage']) && is_array($res['body']['usage'])) ? $res['body']['usage'] : [];
        $byWs = (isset($usage['by_workspace']) && is_array($usage['by_workspace'])) ? $usage['by_workspace'] : [];
        foreach ($byWs as $w) {
            if (empty($w['workspace_id'])) {
                continue;
            }
            $usageMap[(string) $w['workspace_id']] = [
                'credits' => (float) ($w['credits_used'] ?? 0),
                'ai'      => (float) ($w['usd_credits'] ?? 0),
                'cloud'   => (float) ($w['cloud_usd'] ?? 0),
            ];
        }
        _swarmz_hookLog('DailyCronJob.UsageRefreshed', ['period' => 'current_month'], ['workspaces' => count($usageMap)], $api->maskedKey());
    } catch (\Throwable $e) {
        // Usage refresh is best-effort; carry on to status reconcile.
        _swarmz_hookLog('DailyCronJob.UsageRefresh.Failed', [], ['error' => $e->getMessage()], $api->maskedKey());
    }

    // Cache the usage numbers onto each service so the client area is instant.
    foreach ($services as $s) {
        $tenant = (string) $s->tenantid;
        if ($tenant === '' || !isset($usageMap[$tenant])) {
            continue;
        }
        Helpers::cacheUsage((int) $s->serviceid, $usageMap[$tenant]);
    }

    // ── 2. Optional: nudge swarmz's meter cron, IF the host gave us a secret. ─
    // Not callable with the platform key (X-Cron-Secret auth) — only fires when
    // a "Meter Cron Secret" is set in the addon. Pure best-effort.
    $meterSecret = trim((string) Helpers::addonSetting('Meter Cron Secret', ''));
    if ($meterSecret !== '') {
        _swarmz_cron_nudgeMeterCron($baseUrl, $meterSecret);
    }

    // ── 3. Status reconcile: pull terminated workspaces back into WHMCS. ───
    _swarmz_cron_reconcileStatuses($api, $services);

    // ── 4. Renewal safety net. InvoicePaid is best-effort: if the swarmz API
    //      was down at the exact paid-invoice moment, or the WHMCS cron didn't
    //      run that day, the credit cycle would never roll and the customer would
    //      be short for the month. Re-issue an IDEMPOTENT plan refresh for every
    //      active service, anchored at its next-due-date. After a correct renewal
    //      billing_cycle_start == nextduedate, so the swarmz guard skips — this
    //      is a no-op EXCEPT for a genuinely missed renewal, which it heals. We
    //      anchor strictly at the due date (never today()) so a cycle can never
    //      roll early. ──
    _swarmz_cron_refreshActiveServices($services);

    // ── 5. Credit-pack safety net (v1.11.0). Re-grant mapped packs on each
    //      active service's recent paid invoices. The platform's idempotency
    //      key makes this a no-op for anything already granted; it only heals
    //      grants that were missed (API blip at InvoicePaid time, or a pack
    //      paid before the service was provisioned). ──
    foreach ($services as $s) {
        if (strtolower((string) $s->status) !== 'active') {
            continue;
        }
        try {
            Helpers::grantPaidCreditPacksForService((int) $s->serviceid);
        } catch (\Throwable $e) {
            // Best-effort; retried tomorrow.
        }
    }
}

/**
 * Every active/suspended WHMCS service that has a swarmz tenant id stored on it.
 * Mirrors the Console's gather query but adds domainstatus for reconciliation.
 *
 * @return array<int,\stdClass>
 */
function _swarmz_cron_gatherServices(): array
{
    try {
        $rows = Capsule::table('tblhosting as h')
            ->join('tblcustomfieldsvalues as cfv', 'cfv.relid', '=', 'h.id')
            ->join('tblcustomfields as cf', function ($join) {
                $join->on('cf.id', '=', 'cfv.fieldid')
                    ->where('cf.type', '=', 'product')
                    ->where('cf.fieldname', '=', Helpers::CUSTOM_FIELD_TENANT_ID);
            })
            ->whereNotNull('cfv.value')
            ->where('cfv.value', '!=', '')
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->get([
                'h.id as serviceid',
                'h.domainstatus as status',
                'h.nextduedate as nextduedate',
                'cfv.value as tenantid',
            ]);

        $out = [];
        foreach ($rows as $r) {
            $out[] = $r;
        }
        return $out;
    } catch (\Throwable $e) {
        _swarmz_hookLog('DailyCronJob.Gather.Failed', [], ['error' => $e->getMessage()]);
        return [];
    }
}

/**
 * Reconcile swarmz-side workspace status back into WHMCS.
 *
 * We probe each ACTIVE service's workspace with a cheap status read. swarmz
 * exposes status through the lifecycle endpoints (a re-suspend is idempotent,
 * a terminated tenant returns 410). To avoid side effects we use a re-suspend
 * probe ONLY to read status: platform-suspend is idempotent-by-state and
 * returns {already:true} for an already-suspended workspace and 410 for a
 * terminated one — but suspending an ACTIVE workspace WOULD change it, so we
 * must not call suspend on an active service. Instead we read terminated state
 * via platform-usage per-tenant (a terminated workspace still has no active
 * billing but resolves), and rely on platform-sso's 409/410 only at SSO time.
 *
 * Conservative approach taken here: we only act on the unambiguous, side-effect
 * -free signal — a terminated workspace surfaced by platform-plan-refresh's /
 * platform-usage 410 — and suspend the WHMCS service to match. Anything less
 * certain is left for the admin Console + SSO-time gates to surface, so the
 * cron never wrongly suspends a paying customer.
 *
 * @param Api                   $api
 * @param array<int,\stdClass>  $services
 */
function _swarmz_cron_reconcileStatuses(Api $api, array $services): void
{
    foreach ($services as $s) {
        $status = strtolower((string) $s->status);
        $tenant = (string) $s->tenantid;
        $serviceId = (int) $s->serviceid;
        if ($tenant === '' || $serviceId <= 0) {
            continue;
        }
        // Only reconcile services WHMCS currently thinks are Active — those are
        // the ones that would surprise a client if swarmz had killed them.
        if ($status !== 'active') {
            continue;
        }

        try {
            // A per-tenant usage read is side-effect-free and resolves the
            // workspace by tenant_id. A 410 means swarmz terminated it.
            $api->postPlatform('platform-usage', ['tenant_id' => $tenant, 'period' => 'current_month']);
            // 2xx → workspace is alive; nothing to do.
        } catch (SwarmzApiException $e) {
            if ($e->getStatusCode() === 410) {
                // Workspace terminated server-side → suspend the WHMCS service
                // to cut client access. We suspend (not terminate) so a human
                // reviews before any irreversible WHMCS-side teardown.
                _swarmz_cron_suspendService($serviceId, 'Swarmz workspace terminated server-side');
                _swarmz_hookLog(
                    'DailyCronJob.Reconcile.Terminated',
                    ['serviceid' => $serviceId, 'tenant_id' => $tenant],
                    ['action' => 'suspended WHMCS service'],
                    $api->maskedKey()
                );
            }
            // Any other status (404/409/5xx) is ambiguous → leave it for the
            // Console / SSO gates. Don't risk suspending a healthy service.
        } catch (\Throwable $e) {
            // Transport blip — skip this service this run.
            continue;
        }
    }
}

/**
 * Suspend a WHMCS service locally (no swarmz call — swarmz already did it).
 * Uses WHMCS's ModuleSuspend localAPI so all the normal side effects fire.
 */
function _swarmz_cron_suspendService(int $serviceId, string $reason): void
{
    if (!function_exists('localAPI')) {
        return;
    }
    try {
        localAPI('ModuleSuspend', [
            'serviceid'      => $serviceId,
            'suspendreason'  => $reason,
        ]);
    } catch (\Throwable $e) {
        _swarmz_hookLog('DailyCronJob.SuspendService.Failed', ['serviceid' => $serviceId], ['error' => $e->getMessage()]);
    }
}

/**
 * Renewal safety net (daily). Re-issue an idempotent plan refresh for every
 * ACTIVE swarmz service, anchored at its next-due-date, to recover any renewal
 * whose InvoicePaid hook never landed (API blip / cron skipped). The swarmz
 * guard (billing_cycle_start >= cycle_anchor → skip) makes this a no-op for a
 * service already rolled this cycle; only a missed renewal actually refreshes.
 * We deliberately do NOT fall back to today() for the anchor (a blank due date
 * is skipped) so a cycle can never roll early. Best-effort; never throws.
 *
 * @param array<int,\stdClass> $services gathered active/suspended services
 *                                       (with serviceid, status, nextduedate,
 *                                       tenantid).
 */
function _swarmz_cron_refreshActiveServices(array $services): void
{
    $apiKey = trim((string) Helpers::addonSetting('API Key', ''));
    $baseUrl = trim((string) Helpers::addonSetting('API Base URL', ''));
    $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : Helpers::DEFAULT_API_BASE_URL;

    foreach ($services as $s) {
        if (strtolower((string) $s->status) !== 'active') {
            continue; // only active (paid-through) services roll a cycle.
        }
        $serviceId = (int) $s->serviceid;
        $tenant = (string) $s->tenantid;
        if ($serviceId <= 0 || $tenant === '') {
            continue;
        }
        // Anchor strictly at the next-due-date. Blank/zero → skip (never today()).
        $due = trim((string) ($s->nextduedate ?? ''));
        if ($due === '' || strpos($due, '0000-00-00') === 0) {
            continue;
        }
        $anchor = substr($due, 0, 10);

        // Per-service key (server Password) → fall back to the account key.
        $key = $apiKey;
        $perServiceKey = Helpers::resolveServiceServerKey($serviceId);
        if ($perServiceKey !== '') {
            $key = $perServiceKey;
        }
        if ($key === '') {
            continue;
        }

        try {
            $svcApi = new Api($key, $baseUrl);
            $result = $svcApi->planRefresh($tenant, $anchor, true);
            $body = $result['body'] ?? [];
            $skipped = !empty($body['skipped'])
                || (isset($body['result']) && is_array($body['result']) && !empty($body['result']['skipped']));
            // A NON-skip means a renewal had been missed and we just healed it —
            // log it so the host can see the recovery (skips are silent/normal).
            if (!$skipped) {
                _swarmz_hookLog(
                    'DailyCronJob.RenewalRecovered',
                    ['serviceid' => $serviceId, 'tenant_id' => $tenant, 'cycle_anchor' => $anchor],
                    $body,
                    $svcApi->maskedKey()
                );
            }
        } catch (\Throwable $e) {
            // Best-effort; a transient failure is retried on tomorrow's cron.
            continue;
        }
    }
}

/**
 * Refresh the swarmz plan for every swarmz service on a just-paid invoice.
 *
 * @param int $invoiceId
 */
function _swarmz_cron_refreshInvoiceServices(int $invoiceId): void
{
    // Invoice line items of type 'Hosting' carry the service id in relid.
    $serviceIds = [];
    try {
        $rows = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('type', 'Hosting')
            ->get(['relid']);
        foreach ($rows as $r) {
            $id = (int) $r->relid;
            if ($id > 0) {
                $serviceIds[$id] = true;
            }
        }
    } catch (\Throwable $e) {
        _swarmz_hookLog('InvoicePaid.Gather.Failed', ['invoiceid' => $invoiceId], ['error' => $e->getMessage()]);
        return;
    }
    if (empty($serviceIds)) {
        return;
    }

    $apiKey = trim((string) Helpers::addonSetting('API Key', ''));
    $baseUrl = trim((string) Helpers::addonSetting('API Base URL', ''));
    $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : Helpers::DEFAULT_API_BASE_URL;

    foreach (array_keys($serviceIds) as $serviceId) {
        $tenantId = Helpers::getTenantId($serviceId);
        if ($tenantId === null) {
            continue; // Not a (provisioned) swarmz service.
        }

        // Per-service key resolution: prefer the server Password on the
        // service's server; fall back to the addon key. The cron has no
        // $params, so we resolve from the service's product/server directly.
        $key = $apiKey;
        $perServiceKey = Helpers::resolveServiceServerKey($serviceId);
        if ($perServiceKey !== '') {
            $key = $perServiceKey;
        }
        if ($key === '') {
            continue; // No usable key for this service.
        }

        try {
            $api = new Api($key, $baseUrl);
            $cycleAnchor = Helpers::resolveCycleAnchor([], $serviceId);
            $result = $api->planRefresh($tenantId, $cycleAnchor, true);
            _swarmz_hookLog(
                'InvoicePaid.PlanRefresh',
                ['serviceid' => $serviceId, 'tenant_id' => $tenantId, 'cycle_anchor' => $cycleAnchor],
                $result['body'],
                $api->maskedKey()
            );
        } catch (SwarmzApiException $e) {
            // 404 = endpoint not deployed yet; any other = soft. Never throw.
            _swarmz_hookLog(
                'InvoicePaid.PlanRefresh.Skipped',
                ['serviceid' => $serviceId, 'tenant_id' => $tenantId],
                ['status' => $e->getStatusCode(), 'error' => $e->getErrorCode()]
            );
        } catch (\Throwable $e) {
            _swarmz_hookLog('InvoicePaid.PlanRefresh.Skipped', ['serviceid' => $serviceId], ['error' => $e->getMessage()]);
        }
    }
}

/**
 * Fire swarmz's platform-meter-cron with the host-provided X-Cron-Secret.
 * Best-effort; this is the ONLY path that can reach that endpoint from the host
 * side, and only when the host has been given the secret out-of-band.
 */
function _swarmz_cron_nudgeMeterCron(string $baseUrl, string $secret): void
{
    $url = rtrim($baseUrl, '/') . Api::FUNCTIONS_PATH . '/platform-meter-cron';
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Cron-Secret: ' . $secret,
                'User-Agent: swarmz-whmcs/' . Api::VERSION . ' (+https://swarmz.net)',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        _swarmz_hookLog('DailyCronJob.MeterCron', ['url' => $url], ['status' => $code, 'ok' => ($code >= 200 && $code < 300)]);
        unset($raw);
    } catch (\Throwable $e) {
        _swarmz_hookLog('DailyCronJob.MeterCron.Failed', ['url' => $url], ['error' => $e->getMessage()]);
    }
}

/**
 * Logging wrapper that redacts the bearer key. Mirrors the server module's
 * _swarmz_logModuleCall so cron activity shows in the same Module Log.
 */
function _swarmz_hookLog(string $action, $request, $response, string $maskedKey = ''): void
{
    if (!function_exists('logModuleCall')) {
        return;
    }
    $replaceVars = ['sk_live_', 'sk_test_', 'Bearer ', 'X-Cron-Secret'];
    if ($maskedKey !== '') {
        $replaceVars[] = $maskedKey;
    }
    try {
        logModuleCall('swarmz', $action, $request, $response, $response, $replaceVars);
    } catch (\Throwable $e) {
        // Logging must never break the hook.
    }
}
