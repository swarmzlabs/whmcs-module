<?php
/**
 * Swarmz WHMCS Provisioning Module
 *
 * Thin server module for reselling swarmz workspaces from WHMCS. Talks only to
 * the swarmz public platform API (api.swarmz.net by default) — all business
 * logic is server-side.
 *
 * Required endpoints (single-purpose, see reseller-functions-rewrite.md §9):
 *   POST /functions/v1/platform-create
 *   POST /functions/v1/platform-plan
 *   POST /functions/v1/platform-topup
 *   POST /functions/v1/platform-suspend
 *   POST /functions/v1/platform-unsuspend
 *   POST /functions/v1/platform-terminate
 *   POST /functions/v1/platform-usage
 *   POST /functions/v1/platform-sso
 *
 * Auth: every call sends `Authorization: Bearer sk_live_…`, where the key is
 * stored in the WHMCS server "Password" field.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 * @link https://swarmz.net
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/lib/Exceptions.php';
require_once __DIR__ . '/lib/Api.php';
require_once __DIR__ . '/lib/Helpers.php';

use WHMCS\Module\Server\Swarmz\Api;
use WHMCS\Module\Server\Swarmz\Helpers;
use WHMCS\Module\Server\Swarmz\SwarmzApiException;
use WHMCS\Module\Server\Swarmz\SwarmzConfigException;
use WHMCS\Module\Server\Swarmz\SwarmzException;
use WHMCS\Module\Server\Swarmz\SwarmzTransportException;

// =========================================================================
// Meta data — WHMCS reads this once to learn what the module supports.
// =========================================================================

/**
 * Module metadata.
 *
 * @return array
 */
function swarmz_MetaData()
{
    return [
        'DisplayName'              => 'Swarmz',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultSortOrder'         => 1,
        'ServiceSingleSignOnLabel' => 'Open AI Editor',
        'ListAccountsUniqueIdentifierField'       => 'tenant_id',
        'ListAccountsUniqueIdentifierDisplayName' => 'Swarmz Tenant ID',
    ];
}

// =========================================================================
// Product config options — the single per-product knob the WHMCS admin sets.
//
// As of 1.5.0 the ONLY config option is the Plan dropdown: a product is
// provisioned by picking one of the account's named plans. The plan resolves
// to a complete set of entitlements server-side, so there are no positional
// entitlement options to set (or to keep in load-bearing order) anymore.
// =========================================================================

/**
 * Per-product configuration options. The only option is the Plan dropdown,
 * populated live from the account's named plans (platform-plans). Selecting a
 * plan sends its plan_code to platform-create / platform-plan; the platform
 * resolves the full entitlement set from it.
 *
 * @return array
 */
function swarmz_ConfigOptions()
{
    return [
        'plan' => _swarmz_planConfigOption(),
    ];
}

/**
 * Build the "Plan" dropdown. Populates the choices live from the account's
 * named plans via Helpers::fetchPlansSafe(), resolving the API key from
 * whatever $params WHMCS makes available to swarmz_ConfigOptions() (the
 * selected server's Password, falling back to the Reseller Console addon key).
 *
 * Graceful degradation (mirrors the platform-plan-refresh "endpoint
 * maybe-undeployed" tolerance): if the platform-plans call fails or returns
 * none — no key configured yet, the endpoint isn't deployed, a transport blip —
 * we render a single-option dropdown whose only entry is the "— Select a plan —"
 * sentinel, with a Description that tells the admin why no plans loaded. The
 * product-config screen NEVER throws because of this.
 *
 * @return array A WHMCS config-option definition for a dropdown.
 */
function _swarmz_planConfigOption(): array
{
    $none = Helpers::PLAN_NONE_LABEL;
    // WHMCS passes the active product-config $params to swarmz_ConfigOptions()
    // via the global request, but not as a function argument; resolve the key
    // from whatever the server form / addon expose. We build a minimal bag from
    // the superglobals WHMCS populates on the product Module Settings tab, then
    // let resolveApiKey fall back to the addon key.
    $params = _swarmz_configOptionsParams();

    $options = [$none => $none];
    $note = '';
    try {
        $plans = Helpers::fetchPlansSafe($params);
        if (!empty($plans)) {
            foreach ($plans as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $code = isset($p['code']) ? trim((string) $p['code']) : '';
                if ($code === '') {
                    continue;
                }
                $label = isset($p['display_name']) && trim((string) $p['display_name']) !== ''
                    ? trim((string) $p['display_name'])
                    : $code;
                // Option key = the plan code we send as plan_code; label shown to
                // the admin includes the price when present for at-a-glance choice.
                $priceSuffix = '';
                if (isset($p['price_cents']) && is_numeric($p['price_cents']) && (int) $p['price_cents'] > 0) {
                    $cur = isset($p['currency']) ? strtoupper(trim((string) $p['currency'])) : 'USD';
                    $priceSuffix = sprintf(' (%s %s)', number_format(((int) $p['price_cents']) / 100, 2), $cur !== '' ? $cur : 'USD');
                }
                $options[$code] = $label . $priceSuffix . '  [' . $code . ']';
            }
        } else {
            $note = ' No named plans were loaded (the platform-plans endpoint may '
                . 'be unavailable, or your API key is not set yet). Set your key in '
                . 'the server Password field or the Reseller Console addon, define '
                . 'your plans in the Swarmz admin area, then reopen this tab.';
        }
    } catch (\Throwable $e) {
        // Belt-and-braces — fetchPlansSafe already swallows, but never let the
        // config screen throw.
        $note = ' Could not load named plans — check your API key and that the '
            . 'platform-plans endpoint is reachable.';
    }

    return [
        'FriendlyName' => 'Plan',
        'Type'         => 'dropdown',
        'Options'      => $options,
        'Default'      => $none,
        'Description'  => 'Pick the named plan this product provisions — its '
            . 'entitlements (credits, limits, compute, cloud budget) are resolved '
            . 'server-side from the plan. A plan MUST be selected; provisioning '
            . 'fails with a clear error otherwise.' . $note,
    ];
}

/**
 * Assemble a best-effort $params bag for key/base-url resolution during
 * swarmz_ConfigOptions(), where WHMCS does NOT pass a $params argument. We read
 * the server's decrypted Password (the sk_live_ key) when the product-config
 * screen exposes a selected server id; resolveApiKey then falls back to the
 * Reseller Console addon key when that is blank, so a host that configured the
 * key in one place still gets a populated dropdown.
 *
 * @return array
 */
function _swarmz_configOptionsParams(): array
{
    $params = [];

    // The product Module Settings tab posts/serves `servertype`/`servergroup`/
    // a selected server. WHMCS doesn't hand us $params here, so we attempt to
    // resolve the chosen server's key from the request if present; otherwise we
    // rely purely on the addon-key fallback inside resolveApiKey.
    $serverId = 0;
    foreach (['server', 'serverid', 'serverhostname'] as $k) {
        if (isset($_REQUEST[$k]) && is_numeric($_REQUEST[$k])) {
            $serverId = (int) $_REQUEST[$k];
            break;
        }
    }

    if ($serverId > 0 && function_exists('localAPI')) {
        try {
            $row = \WHMCS\Database\Capsule::table('tblservers')->where('id', $serverId)->first(['password', 'hostname', 'secure']);
            if ($row) {
                if (!empty($row->hostname)) {
                    $params['serverhostname'] = (string) $row->hostname;
                    $params['serversecure'] = !empty($row->secure);
                }
                if (!empty($row->password)) {
                    try {
                        $dec = localAPI('DecryptPassword', ['password2' => (string) $row->password]);
                        if (isset($dec['password']) && is_string($dec['password']) && trim($dec['password']) !== '') {
                            $params['serverpassword'] = trim($dec['password']);
                        }
                    } catch (\Throwable $e) {
                        // fall through to addon-key resolution
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore — addon-key fallback in resolveApiKey still applies
        }
    }

    return $params;
}

// =========================================================================
// Lifecycle — CreateAccount / Suspend / Unsuspend / Terminate / ChangePackage
// =========================================================================

/**
 * Provision a new workspace (called when WHMCS marks the service Active).
 * Idempotent on (account, external_ref): a retried call returns the same tenant.
 *
 * @param array $params
 * @return string "success" on success, or a human-readable error string.
 */
function swarmz_CreateAccount(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $externalRef = Helpers::buildExternalRef($serviceId);
    $whu = Helpers::buildWhu($params);
    $planCode = Helpers::getSelectedPlanCode($params);

    // A named plan is mandatory: it defines the workspace's entitlements. With
    // no plan selected there is nothing to provision against, so fail fast with
    // an actionable message rather than creating an entitlement-less workspace.
    if ($planCode === '') {
        return 'Swarmz: select a Swarmz plan on the product\'s Module Settings tab before provisioning.';
    }

    $api = null;
    try {
        $api = Helpers::makeApiClient($params);

        // Provision by plan name: the platform resolves the full entitlement set
        // from plan_code server-side.
        //
        // cycle_anchor = the service's next-due-date (the FIRST renewal). The
        // platform stores it as the workspace's billing-cycle anchor so the
        // order invoice's own InvoicePaid hook (which fires a plan refresh
        // anchored at this same date) is an idempotent skip instead of rolling
        // + re-charging month 1. Server-side guards ignore a past/blank anchor
        // and anything beyond ~45 days (non-monthly cycles keep legacy behavior).
        $body = [
            'external_ref' => $externalRef,
            'whu'          => $whu,
            'plan_code'    => $planCode,
        ];

        // Prompt-box intent (v1.9.0): a prompt the customer typed on the
        // host's storefront, bound to this service by the addon's checkout
        // hooks. The platform parks it on the workspace and auto-starts the
        // first project from it on the customer's first login. Read is
        // best-effort — no addon / no table / no intent all mean "omit".
        $initialPrompt = Helpers::pendingPromptForService($serviceId);
        if ($initialPrompt !== null) {
            $body['initial_prompt'] = $initialPrompt;
        }

        // Only send cycle_anchor when there is a REAL future due date.
        // resolveCycleAnchor returns '' when there is none (it never falls back
        // to today()); omitting the field entirely is cleaner than sending a
        // value the platform would just discard, and matches the daily cron.
        $cycleAnchor = Helpers::resolveCycleAnchor($params, $serviceId);
        if ($cycleAnchor !== '') {
            $body['cycle_anchor'] = $cycleAnchor;
        }

        $result = $api->postPlatform('platform-create', $body);

        _swarmz_logModuleCall(
            'CreateAccount',
            $body,
            $result['body'],
            $api->maskedKey()
        );

        $resp = $result['body'];
        $tenantId = isset($resp['tenant_id']) ? (string) $resp['tenant_id'] : '';
        $dashboardUrl = isset($resp['dashboard_url']) ? (string) $resp['dashboard_url'] : '';

        if ($tenantId === '') {
            return 'Swarmz: platform-create succeeded but no tenant_id was returned.';
        }

        $productId = isset($params['pid']) ? (int) $params['pid'] : null;
        Helpers::setTenantId($serviceId, $tenantId, $dashboardUrl, $productId);

        // The intent made it to the platform with this provision — mark it
        // consumed so a later re-provision can't re-send a stale prompt.
        if ($initialPrompt !== null) {
            Helpers::markPromptUsedForService($serviceId);
        }

        // Credit-pack catch-up (v1.11.0): if the customer ordered a mapped
        // credit-pack addon WITH this product, its invoice was usually paid
        // BEFORE this provision stored the tenant id — so the InvoicePaid
        // grant deferred. Now that the tenant exists, grant those packs.
        // Idempotent (platform dedupes per invoice line) and best-effort.
        try {
            Helpers::grantPaidCreditPacksForService($serviceId);
        } catch (\Throwable $e) {
            // Never fail a successful provision over a pack grant; the daily
            // cron sweep retries idempotently.
        }

        return 'success';
    } catch (\Throwable $e) {
        _swarmz_logModuleCall('CreateAccount.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        return Helpers::formatError($e, $api ? $api->maskedKey() : null);
    }
}

/**
 * Suspend the workspace (pauses pods + cloud, unpublishes sites, blocks SSO).
 * Idempotent-by-state: a retry on an already-suspended tenant returns success.
 *
 * @param array $params
 * @return string
 */
function swarmz_SuspendAccount(array $params)
{
    return _swarmz_simpleLifecycle('SuspendAccount', 'platform-suspend', $params);
}

/**
 * Resume a previously-suspended workspace.
 *
 * @param array $params
 * @return string
 */
function swarmz_UnsuspendAccount(array $params)
{
    return _swarmz_simpleLifecycle('UnsuspendAccount', 'platform-unsuspend', $params);
}

/**
 * Permanently delete the workspace (full teardown). No undo.
 *
 * @param array $params
 * @return string
 */
function swarmz_TerminateAccount(array $params)
{
    return _swarmz_simpleLifecycle('TerminateAccount', 'platform-terminate', $params);
}

/**
 * Push new entitlements when the WHMCS admin upgrades/downgrades the product.
 * Called by WHMCS on package change.
 *
 * @param array $params
 * @return string
 */
function swarmz_ChangePackage(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $externalRef = Helpers::buildExternalRef($serviceId);
    $planCode = Helpers::getSelectedPlanCode($params);

    // The new plan is identified solely by plan_code; without one there is
    // nothing to change the workspace to. Fail with a clear message.
    if ($planCode === '') {
        return 'Swarmz: select a Swarmz plan on the product\'s Module Settings tab before changing the package.';
    }

    $api = null;
    try {
        $api = Helpers::makeApiClient($params);

        // Plan-by-name upgrade/downgrade: send the selected plan_code; the
        // platform resolves the entitlements server-side.
        $body = [
            'external_ref' => $externalRef,
            'plan_code'    => $planCode,
        ];

        $tenantId = Helpers::getTenantId($serviceId);
        if ($tenantId !== null) {
            $body['tenant_id'] = $tenantId;
        }

        $result = $api->postPlatform('platform-plan', $body);
        _swarmz_logModuleCall('ChangePackage', $body, $result['body'], $api->maskedKey());

        // NO plan-refresh here (v1.6.0). WHMCS runs ChangePackage when the
        // PRORATED upgrade invoice is paid — mid-cycle, NOT at a billing
        // boundary. The platform now prorates server-side inside the plan
        // assignment itself: an upgrade grants (new − old) monthly credits ×
        // time-remaining-in-cycle and meters the host for exactly that
        // prorated amount; a downgrade applies the new caps immediately and
        // takes full effect at renewal (no clawback, matching WHMCS's
        // no-refund default). The old refresh call anchored at next-due-date
        // rolled the WHOLE cycle instead — full new-plan grant + a FULL
        // monthly charge to the host for a prorated end-customer payment.
        // Renewal-boundary refreshes remain owned by the InvoicePaid hook +
        // the daily safety net (hooks.php).

        return 'success';
    } catch (\Throwable $e) {
        _swarmz_logModuleCall('ChangePackage.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        return Helpers::formatError($e, $api ? $api->maskedKey() : null);
    }
}

// =========================================================================
// SSO — client clicks "Open AI Editor" → WHMCS calls this → we mint a redirect.
// =========================================================================

/**
 * Service-level SSO. Called when the client clicks the SSO button in the
 * client area (or admin uses "Login as User"). Returns a redirectTo URL.
 *
 * @param array $params
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function swarmz_ServiceSingleSignOn(array $params)
{
    return _swarmz_doSso($params);
}

/**
 * Custom client-area functions the client may invoke. `launch` is our SSO
 * launcher (swarmz_launch); it re-mints a fresh platform-sso redirect on EVERY
 * click and 302s straight to the editor.
 *
 * We register it in ClientAreaAllowedFunctions — NOT ClientAreaCustomButtonArray
 * — deliberately: this authorises the `modop=custom&a=launch` request WITHOUT
 * WHMCS auto-rendering a second (same-tab) default button. Our client-area
 * template renders the one host-branded launcher itself, and it opens in a new
 * tab.
 *
 * Why a custom action at all, instead of WHMCS's built-in `dosinglesignon` link
 * (which swarmz_ServiceSingleSignOn also backs): the built-in flow is
 * repeat-suppressed — after the first launch, a second same-tab click on a
 * bfcache-restored product page frequently produces NO navigation (WHMCS treats
 * the SSO as already-satisfied in that session, and the customer's app now lives
 * on a different apex than WHMCS via a custom domain). The custom action always
 * runs server-side and re-mints; combined with the new-tab button, repeat clicks
 * reliably land in the editor.
 *
 * @return array<int,string>
 */
function swarmz_ClientAreaAllowedFunctions()
{
    return ['launch'];
}

/**
 * SSO launcher (custom client-area action, invoked via
 * clientarea.php?action=productdetails&id=<sid>&modop=custom&a=launch).
 *
 * Mints a fresh platform-sso redirect and sends the browser there with a
 * Location header, then exits — so every click is a new round-trip that
 * cannot be repeat-suppressed. The client-area button targets _blank, so the
 * WHMCS product page stays put and the editor opens in a new tab.
 *
 * On failure we do NOT redirect; we return the error string so WHMCS surfaces
 * it through its normal module-error path (and AfterModuleCustomFailed fires).
 *
 * @param array $params
 * @return string Empty string is never returned on success (we exit); a
 *                non-empty error string on failure.
 */
function swarmz_launch(array $params)
{
    $result = _swarmz_doSso($params);

    if (!empty($result['success']) && !empty($result['redirectTo'])) {
        _swarmz_redirect((string) $result['redirectTo']);
        // _swarmz_redirect exits; the line below is unreachable in WHMCS and
        // only there for the CLI/test harness where headers are a no-op.
        return '';
    }

    return isset($result['errorMsg']) && $result['errorMsg'] !== ''
        ? (string) $result['errorMsg']
        : 'Swarmz: could not start the editor session. Please try again.';
}

// =========================================================================
// Usage metrics — WHMCS polls this to render usage in the client area.
// =========================================================================

/**
 * Return current-period usage for the workspace.
 *
 * The live /platform-usage endpoint returns the shape:
 *   { ok: true,
 *     usage: {
 *       credits_used: number,
 *       usd_credits:  number,
 *       cloud_usd:    number,
 *       period:       { from: ISO, to: ISO, label: "current_month"|"last_month"|"ytd" },
 *       by_workspace: [{ workspace_id, credits_used, usd_credits, cloud_usd }]
 *     },
 *     balances: {            // point-in-time per-pool standing balances
 *       purchased_total, topup_available, included_remaining, rollover_remaining,
 *       by_workspace: [{
 *         topup_available, purchased_total, purchased_consumed,
 *         included_credits, included_used, included_remaining,
 *         rollover_credits, rollover_used, rollover_remaining,
 *         caps: { credits_per_day, monthly_credit_cap, monthly_credits, ... }
 *       }]
 *     } | null
 *   }
 *
 * Credits are THREE separate pools — never one merged number:
 *   1. Free   — daily allowance (credits_per_day), optional monthly free cap.
 *   2. Monthly — paid grant (monthly_credits / included_credits), may roll over.
 *   3. Top-up — one-off purchased credits.
 *
 * As of 1.5.0 ALL displayed plan caps + usage come from the platform-usage API
 * response (usage + balances + balances.by_workspace[].caps) — never from any
 * locally-configured option (those were removed; the plan now defines
 * everything server-side).
 *
 * IMPORTANT: the endpoint resolves the workspace ONLY by tenant_id; passing
 * external_ref alone returns account-wide aggregate (a leak in this context).
 * We therefore only call it once we know the tenant_id — otherwise we return
 * a tidy "not provisioned yet" response without hitting the API.
 *
 * @param array $params
 * @return array
 */
function swarmz_UsageUpdate(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $externalRef = Helpers::buildExternalRef($serviceId);
    $tenantId = Helpers::getTenantId($serviceId);

    // Empty per-pool view used before any live data is available (not
    // provisioned yet, or an API failure with no cache). Everything is unknown
    // until the platform-usage API answers — we never invent numbers locally.
    $emptyCredits = _swarmz_emptyCreditPools();

    // Not provisioned yet → don't hit the API; return a friendly placeholder
    // so the client area template can render without errors. The endpoint
    // does NOT resolve by external_ref alone — it would silently return the
    // entire account's aggregate, which is the wrong number to show this
    // client.
    if ($tenantId === null) {
        return array_merge([
            'success'              => true,
            'creditsUsed'          => 0,
            'cloudUsd'             => 0.0,
            'usdCredits'           => 0.0,
            'projectsCount'        => 0,
            'domainsCount'         => null,
            'domainsLimit'         => null,
            'publishedCount'       => null,
            'publishedLimit'       => null,
            'customDomainsEnabled' => true,
            'periodStart'          => null,
            'periodEnd'            => null,
            'raw'                  => [],
        ], $emptyCredits);
    }

    $api = null;
    try {
        $api = Helpers::makeApiClient($params);

        // external_ref is included for log-correlation only; the endpoint
        // ignores it for resolution and uses tenant_id exclusively.
        $body = [
            'tenant_id'    => $tenantId,
            'external_ref' => $externalRef,
            'period'       => 'current_month',
        ];

        $result = $api->postPlatform('platform-usage', $body);
        $resp = $result['body'];

        _swarmz_logModuleCall('UsageUpdate', $body, $resp, $api->maskedKey());

        $usage = isset($resp['usage']) && is_array($resp['usage']) ? $resp['usage'] : [];
        $period = isset($usage['period']) && is_array($usage['period']) ? $usage['period'] : [];

        // Per-pool standing balances + plan caps (point-in-time), straight from
        // the API. balances.by_workspace[0].caps carries every plan limit the
        // client area shows; we read it exclusively.
        $balances = $resp['balances'] ?? null;
        $credits = _swarmz_creditPoolsFromBalances($balances, $emptyCredits);
        $caps = _swarmz_capsFromBalances($balances);

        return array_merge([
            'success'              => true,
            'creditsUsed'          => isset($usage['credits_used']) ? (float) $usage['credits_used'] : 0.0,
            'cloudUsd'             => isset($usage['cloud_usd'])    ? (float) $usage['cloud_usd']    : 0.0,
            'usdCredits'           => isset($usage['usd_credits'])  ? (float) $usage['usd_credits']  : 0.0,
            // The live endpoint doesn't return project/domain counts; null tells
            // the template to omit the field rather than show "0".
            'projectsCount'        => null,
            'domainsCount'         => null,
            'domainsLimit'         => $caps['domainsLimit'],
            'publishedCount'       => null,
            'publishedLimit'       => $caps['publishedLimit'],
            'customDomainsEnabled' => $caps['customDomainsEnabled'],
            'periodStart'          => $period['from']  ?? null,
            'periodEnd'            => $period['to']    ?? null,
            'periodLabel'          => $period['label'] ?? null,
            'raw'                  => $usage,
        ], $credits);
    } catch (SwarmzApiException $e) {
        // `usage_read_failed` is a known soft failure — the metrics view can be
        // briefly incomplete during a server-side migration. Treat as a
        // "no data yet" outcome instead of an error so the client area still
        // renders the SSO button.
        $isSoft = ($e->getErrorCode() === 'usage_read_failed');
        _swarmz_logModuleCall(
            $isSoft ? 'UsageUpdate.SoftUnavailable' : 'UsageUpdate.Error',
            $params,
            ['error' => $e->getMessage(), 'status' => $e->getStatusCode()],
            $api ? $api->maskedKey() : ''
        );
        // On a soft/hard failure, fall back to the daily-cron usage cache (if
        // present) so the client area still shows recent spend numbers rather
        // than zeros. Plan caps are unknown on this path (no live balances).
        if ($isSoft) {
            return _swarmz_usageFromCacheOrZero($serviceId, $emptyCredits, true, null, 'metrics_unavailable');
        }
        return _swarmz_usageFromCacheOrZero(
            $serviceId,
            $emptyCredits,
            false,
            Helpers::formatError($e, $api ? $api->maskedKey() : null)
        );
    } catch (\Throwable $e) {
        _swarmz_logModuleCall('UsageUpdate.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        return _swarmz_usageFromCacheOrZero(
            $serviceId,
            $emptyCredits,
            false,
            Helpers::formatError($e, $api ? $api->maskedKey() : null)
        );
    }
}

/**
 * The "nothing known yet" three-pool credit view. Used before any live
 * platform-usage data is available; every value is null/0 so the template
 * renders placeholders rather than fabricated numbers.
 *
 * @return array<string,mixed>
 */
function _swarmz_emptyCreditPools(): array
{
    return [
        'freeDaily'        => null,   // null = unknown/unlimited until API says
        'freeDailyUsed'    => null,
        'freeMonthlyCap'   => null,
        // Free-lane grant cadence + live counters (v1.8.0, API 2026-07). null
        // mode = unknown (older API, or plan assigned before modes existed)
        // → the daily-allowance rendering is the legacy default.
        'freeMode'         => null,   // 'daily' | 'monthly' | 'one_time' | 'none'
        'freeTotal'        => null,   // mode-resolved pool size (null = unlimited/unknown)
        'freeUsed'         => null,   // mode-resolved used (today / this cycle / ever)
        'freeRemaining'    => null,   // spendable now per the platform's spend fn
        // Cloud/AI grant cadence ('monthly' | 'one_time' | 'none'; null = unknown
        // → legacy per-cycle wording).
        'cloudMode'        => null,
        'aiMode'           => null,
        'monthlyCredits'   => 0,
        'monthlyUsed'      => null,
        'monthlyRemaining' => null,
        'rolloverCredits'  => null,
        'rolloverMonths'   => 0,
        'topupCredits'     => 0,
        'topupUsed'        => null,
        'topupRemaining'   => null,
        // 3-credit model lanes — shown as CREDITS (never USD).
        'cloudGrant'          => 0,
        'cloudGrantRemaining' => 0,
        'aiGrant'             => 0,
        'aiGrantRemaining'    => 0,
        'creditsSource'    => 'api',
    ];
}

/**
 * Extract the plan limits the client area shows (custom-domain + published-app
 * caps, and the custom-domains master switch) from a platform-usage `balances`
 * object. Reads balances.by_workspace[0].caps — the per-tenant plan caps the
 * platform resolved from the selected plan.
 *
 * Returns the 0/null = UNLIMITED sentinel as null so the template shows "∞".
 *
 * @param mixed $balances The response `balances` object (or null).
 * @return array{domainsLimit:?int, publishedLimit:?int, customDomainsEnabled:bool}
 */
function _swarmz_capsFromBalances($balances): array
{
    $caps = [];
    if (is_array($balances) && isset($balances['by_workspace'][0]['caps']) && is_array($balances['by_workspace'][0]['caps'])) {
        $caps = $balances['by_workspace'][0]['caps'];
    }

    // custom_domains_enabled is not a numeric cap; the platform may expose it on
    // caps. Absent → default allowed (the count cap still gates how many).
    $customDomainsEnabled = true;
    if (array_key_exists('custom_domains_enabled', $caps)) {
        $v = $caps['custom_domains_enabled'];
        $customDomainsEnabled = is_bool($v)
            ? $v
            : in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }

    return [
        'domainsLimit'         => Helpers::unlimitedSentinel($caps['max_custom_domains'] ?? null),
        'publishedLimit'       => Helpers::unlimitedSentinel($caps['max_published_projects'] ?? null),
        'customDomainsEnabled' => $customDomainsEnabled,
    ];
}

/**
 * Map the live `balances` section of a platform-usage response into the
 * three-pool credit view the client area renders. Starts from the "nothing
 * known" base ($baseCredits) and fills in whatever the API returned, so a
 * missing field stays a placeholder rather than a fabricated number.
 *
 * The module always calls platform-usage with a single tenant_id, so the
 * relevant per-workspace balance is the (only) entry in balances.by_workspace;
 * we read that one row. If balances is null/empty (endpoint not deployed yet or
 * a non-fatal balances read failure), the base view is returned verbatim.
 *
 * @param mixed              $balances    The response `balances` object (or null).
 * @param array<string,mixed> $baseCredits The "nothing known yet" base view.
 * @return array<string,mixed>
 */
function _swarmz_creditPoolsFromBalances($balances, array $baseCredits): array
{
    if (!is_array($balances)) {
        return $baseCredits;
    }

    // Prefer the per-workspace row (point-in-time, this tenant only). Fall back
    // to the account-wide sums, which for a single-tenant query are identical.
    $ws = null;
    if (isset($balances['by_workspace'][0]) && is_array($balances['by_workspace'][0])) {
        $ws = $balances['by_workspace'][0];
    }
    $caps = (is_array($ws) && isset($ws['caps']) && is_array($ws['caps'])) ? $ws['caps'] : [];

    $num = static function ($v) {
        return (is_numeric($v)) ? (float) $v : null;
    };

    $out = $baseCredits;
    $out['creditsSource'] = 'live';

    // ---- Rollover cadence (months) from caps when present. ----
    if (array_key_exists('rollover_months', $caps) && $num($caps['rollover_months']) !== null) {
        $out['rolloverMonths'] = (int) $num($caps['rollover_months']);
    }

    // ---- Free pool: daily allowance + optional monthly cap (from caps) ----
    // caps.credits_per_day === null means unlimited; keep null so the template
    // shows "unlimited" rather than 0.
    if (array_key_exists('credits_per_day', $caps)) {
        $out['freeDaily'] = $num($caps['credits_per_day']); // may be null = unlimited
    }
    if (array_key_exists('monthly_credit_cap', $caps)) {
        $out['freeMonthlyCap'] = $num($caps['monthly_credit_cap']);
    }

    // ---- Lane grant cadence (caps.*_credit_mode) + live free-lane counters.
    // A plan grants free credits in ONE of three modes: 'daily' (n/day, resets
    // 00:00 UTC, optional monthly cap), 'monthly' (n once per billing month,
    // replenished each cycle) or 'one_time' (n once EVER, never replenished) —
    // plus 'none'. caps.credits_per_day is 0 for the non-daily modes, so the
    // legacy daily-only rendering showed "0 / 0" while the customer actually
    // held a one-time/monthly grant. The live free_* counters are the
    // authoritative lane state (what the platform's spend function enforces):
    // for one_time/monthly the monthly pair IS the grant + its usage.
    // Older API deployments return none of these keys → modes stay null and
    // the template keeps the legacy daily rendering.
    $str = static function ($v) {
        return (is_string($v) && $v !== '') ? $v : null;
    };
    $out['freeMode']  = $str($caps['free_credit_mode'] ?? null);
    $out['cloudMode'] = $str($caps['cloud_credit_mode'] ?? null);
    $out['aiMode']    = $str($caps['ai_credit_mode'] ?? null);

    if ($ws !== null && array_key_exists('free_remaining', $ws)) {
        $fDailyLimit   = $num($ws['free_daily_limit'] ?? null);
        $fDailyUsed    = $num($ws['free_daily_used'] ?? null);
        $fMonthlyLimit = $num($ws['free_monthly_limit'] ?? null);
        $fMonthlyUsed  = $num($ws['free_monthly_used'] ?? null);

        $out['freeRemaining'] = $num($ws['free_remaining'] ?? null); // null = unlimited
        switch ($out['freeMode']) {
            case 'one_time':
            case 'monthly':
                $out['freeTotal'] = $fMonthlyLimit;
                $out['freeUsed']  = $fMonthlyUsed;
                break;
            case 'none':
                $out['freeTotal'] = 0.0;
                break;
            default:
                // 'daily' or unknown/legacy: headline = today's allowance.
                // Prefer the live per-day ceiling over caps.credits_per_day —
                // the billing row is what the spend function enforces.
                $out['freeTotal'] = $fDailyLimit;
                $out['freeUsed']  = $fDailyUsed;
                if ($fDailyLimit !== null) {
                    $out['freeDaily'] = $fDailyLimit;
                }
                $out['freeDailyUsed'] = $fDailyUsed;
                break;
        }
    }

    // ---- Monthly pool: included grant (size / used / remaining) + rollover ----
    if ($ws !== null && isset($ws['included_credits'])) {
        $included   = (float) ($ws['included_credits'] ?? 0);
        $includedUs = (float) ($ws['included_used'] ?? 0);
        $includedRe = isset($ws['included_remaining'])
            ? (float) $ws['included_remaining']
            : max(0.0, $included - $includedUs);
        $out['monthlyCredits']   = $included;
        $out['monthlyUsed']      = $includedUs;
        $out['monthlyRemaining'] = $includedRe;

        $out['rolloverCredits']  = isset($ws['rollover_remaining'])
            ? (float) $ws['rollover_remaining']
            : (isset($balances['rollover_remaining']) ? (float) $balances['rollover_remaining'] : null);
    }

    // ---- Top-up pool: purchased total / consumed / still-available ----
    if ($ws !== null && (isset($ws['topup_available']) || isset($ws['purchased_total']))) {
        $purchased = (float) ($ws['purchased_total'] ?? 0);
        $consumed  = (float) ($ws['purchased_consumed'] ?? 0);
        $available = isset($ws['topup_available'])
            ? (float) $ws['topup_available']
            : max(0.0, $purchased - $consumed);
        // Show lifetime purchased as the pool size when known; else the available.
        $out['topupCredits']   = $purchased > 0 ? $purchased : $available;
        $out['topupUsed']      = $consumed;
        $out['topupRemaining'] = $available;
    }

    // ---- Cloud + AI credit lanes (3-credit model). Grant size from
    //      caps.monthly_{cloud,ai}_credits; remaining from the per-workspace
    //      grant pools. Shown as CREDITS to the WHU — never USD. ----
    if (array_key_exists('monthly_cloud_credits', $caps) && $num($caps['monthly_cloud_credits']) !== null) {
        $out['cloudGrant'] = $num($caps['monthly_cloud_credits']);
    }
    if ($ws !== null && isset($ws['cloud_grant_remaining'])) {
        $out['cloudGrantRemaining'] = (float) $ws['cloud_grant_remaining'];
    }
    if (array_key_exists('monthly_ai_credits', $caps) && $num($caps['monthly_ai_credits']) !== null) {
        $out['aiGrant'] = $num($caps['monthly_ai_credits']);
    }
    if ($ws !== null && isset($ws['ai_grant_remaining'])) {
        $out['aiGrantRemaining'] = (float) $ws['ai_grant_remaining'];
    }

    return $out;
}

/**
 * Format a credit figure for display: whole numbers render bare ("15"),
 * fractional spend keeps one decimal ("7.5") instead of silently truncating —
 * one-off grants with fractional consumption are the norm on the free lane.
 *
 * @param mixed $v
 * @return string
 */
function _swarmz_fmtCredits($v): string
{
    if (!is_numeric($v)) {
        return '0';
    }
    $f = (float) $v;
    return (floor($f) === $f) ? (string) (int) $f : number_format($f, 1);
}

/**
 * Resolve the free-credits card into a display decision the template renders
 * without any arithmetic:
 *
 *   kind      'none' | 'unlimited' | 'daily' | 'monthly' | 'one_time'
 *   remaining string      pre-formatted numerator
 *   total     string|null pre-formatted denominator (null = no denominator)
 *
 * The grant cadence comes from the plan (freeMode). When unknown — an older
 * API deployment, or a plan assigned before grant modes existed — the
 * historical daily-allowance behaviour is preserved.
 *
 * @param array<string,mixed> $usage swarmz_UsageUpdate result.
 * @return array{kind:string, remaining:string, total:?string}
 */
function _swarmz_freeCardView(array $usage): array
{
    $mode = $usage['freeMode'] ?? null;

    if ($mode === 'none') {
        return ['kind' => 'none', 'remaining' => '0', 'total' => null];
    }

    if ($mode === 'one_time' || $mode === 'monthly') {
        $total = $usage['freeTotal'] ?? null;
        if (!is_numeric($total) || (float) $total <= 0) {
            // Mode says a grant exists but the lane was never seeded — render
            // "not included" rather than a fabricated number.
            return ['kind' => 'none', 'remaining' => '0', 'total' => null];
        }
        $remaining = $usage['freeRemaining'] ?? null;
        if (!is_numeric($remaining)) {
            $used = $usage['freeUsed'] ?? null;
            $remaining = max(0.0, (float) $total - (is_numeric($used) ? (float) $used : 0.0));
        }
        return [
            'kind'      => $mode,
            'remaining' => _swarmz_fmtCredits($remaining),
            'total'     => _swarmz_fmtCredits($total),
        ];
    }

    // 'daily', or unknown mode → legacy default. Live lane values when the
    // API returned them, else the caps-derived freeDaily/freeDailyUsed pair.
    $daily = $usage['freeTotal'] ?? null;
    if (!is_numeric($daily)) {
        $daily = $usage['freeDaily'] ?? null;
    }
    if ($daily === null || !is_numeric($daily)) {
        // No per-day ceiling known: unlimited (or nothing known yet — the
        // pre-existing placeholder behaviour).
        return ['kind' => 'unlimited', 'remaining' => '', 'total' => null];
    }
    if ((float) $daily <= 0) {
        // 0/day in daily mode = the plan simply has no free credits.
        return ['kind' => 'none', 'remaining' => '0', 'total' => null];
    }
    $remaining = $usage['freeRemaining'] ?? null;
    if (!is_numeric($remaining)) {
        $used = $usage['freeUsed'] ?? null;
        if (!is_numeric($used)) {
            $used = $usage['freeDailyUsed'] ?? null;
        }
        $remaining = is_numeric($used)
            ? max(0.0, (float) $daily - (float) $used)
            : (float) $daily;
    }
    return [
        'kind'      => 'daily',
        'remaining' => _swarmz_fmtCredits($remaining),
        'total'     => _swarmz_fmtCredits($daily),
    ];
}

/**
 * Percentage of a pool still remaining (0-100 int), or null when unknown /
 * not meaningful (no total). Used for the client-area progress bars — computed
 * here so the template stays arithmetic-free.
 *
 * @param mixed $remaining
 * @param mixed $total
 * @return int|null
 */
function _swarmz_pctOf($remaining, $total): ?int
{
    if (!is_numeric($remaining) || !is_numeric($total) || (float) $total <= 0) {
        return null;
    }
    $pct = (int) round(((float) $remaining / (float) $total) * 100);
    return max(0, min(100, $pct));
}

/**
 * Build a UsageUpdate result from the daily-cron usage cache when a live read
 * failed, falling back to zeros if there is no cache. Plan caps are unknown on
 * this path (no live balances), so the limits render as placeholders.
 *
 * @param int                $serviceId
 * @param array<string,mixed> $baseCredits The "nothing known yet" credit view.
 * @param bool               $success
 * @param string|null        $errorMsg
 * @param string|null        $note
 * @return array
 */
function _swarmz_usageFromCacheOrZero(int $serviceId, array $baseCredits, bool $success, ?string $errorMsg = null, ?string $note = null): array
{
    $cache = Helpers::getCachedUsage($serviceId);
    $out = array_merge([
        'success'              => $success,
        'creditsUsed'          => $cache !== null ? (float) $cache['credits'] : 0.0,
        'cloudUsd'             => $cache !== null ? (float) $cache['cloud'] : 0.0,
        'usdCredits'           => $cache !== null ? (float) $cache['ai'] : 0.0,
        'projectsCount'        => null,
        'domainsCount'         => null,
        'domainsLimit'         => null,
        'publishedCount'       => null,
        'publishedLimit'       => null,
        'customDomainsEnabled' => true,
        'periodStart'          => null,
        'periodEnd'            => null,
        'periodLabel'          => null,
        'raw'                  => [],
    ], $baseCredits);
    if ($cache !== null) {
        $out['fromCache'] = true;
        $out['cachedAt'] = $cache['cached_at'];
    }
    if ($errorMsg !== null) {
        $out['errorMsg'] = $errorMsg;
    }
    if ($note !== null) {
        $out['note'] = $note;
    }
    return $out;
}

// =========================================================================
// Admin & client area surfaces.
// =========================================================================

/**
 * Extra fields shown on the admin "Service Details" tab.
 *
 * @param array $params
 * @return array
 */
function swarmz_AdminServicesTabFields(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $tenantId = Helpers::getTenantId($serviceId);
    $dashboardUrl = Helpers::getDashboardUrl($serviceId);

    $tenantLabel = $tenantId !== null
        ? '<code style="font-size:12px;">' . htmlspecialchars($tenantId, ENT_QUOTES) . '</code>'
        : '<em>not provisioned yet</em>';

    $dashLink = $dashboardUrl !== null
        ? '<a href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES) . '" target="_blank" rel="noopener">Open dashboard &raquo;</a>'
        : '<em>not provisioned yet</em>';

    return [
        'Swarmz Tenant'    => $tenantLabel,
        'Swarmz Dashboard' => $dashLink,
    ];
}

/**
 * "Login as User" button shown on the admin service page (top right).
 *
 * @param array $params
 * @return string Raw HTML for the button.
 */
function swarmz_AdminLink(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    if ($serviceId <= 0) {
        return '';
    }
    // Supplying a direct AdminLink is the way to expose a custom admin CTA. We
    // render a tiny form that posts to the WHMCS single-sign-on endpoint, which
    // routes through the client/service SSO flow (swarmz_ServiceSingleSignOn).
    $url = 'sso.php?direct=true&sso_redirect_action=service&sso_redirect_id=' . $serviceId;
    return '<form action="' . htmlspecialchars($url, ENT_QUOTES) . '" method="post" target="_blank" style="display:inline;">'
        . '<button type="submit" class="btn btn-info">Open AI Editor</button>'
        . '</form>';
}

/**
 * Client-area output for this service. Renders the "Open AI Editor" button
 * plus a usage panel.
 *
 * @param array $params
 * @return array
 */
function swarmz_ClientArea(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $tenantId = Helpers::getTenantId($serviceId);
    $dashboardUrl = Helpers::getDashboardUrl($serviceId);

    // Point the SSO button at our custom launcher action (swarmz_launch), NOT
    // the built-in &dosinglesignon=1 flow. The launcher re-mints on every click
    // and the template opens it in a new tab, so repeat launches never no-op
    // (the built-in flow is repeat-suppressed on bfcache-restored pages). The
    // template submits this as a POST form (modop=custom requires POST) with
    // target="_blank".
    $ssoUrl = 'clientarea.php?action=productdetails&id=' . $serviceId . '&modop=custom&a=launch';

    // Pull usage (best-effort; on failure, render placeholders).
    $usage = swarmz_UsageUpdate($params);

    // Resolve the free-credits card once, in PHP — kind + pre-formatted
    // numbers — so the template renders it without arithmetic.
    $freeCard = _swarmz_freeCardView($usage);

    // Progress-bar percentages (remaining share of each pool), template-ready.
    $freePct = null;
    if (in_array($freeCard['kind'], ['daily', 'monthly', 'one_time'], true) && $freeCard['total'] !== null) {
        $freePct = _swarmz_pctOf((float) str_replace(',', '', $freeCard['remaining']), (float) str_replace(',', '', (string) $freeCard['total']));
    }
    $monthlyRemForPct = $usage['monthlyRemaining'] ?? null;
    if (!is_numeric($monthlyRemForPct)) {
        $monthlyRemForPct = $usage['monthlyCredits'] ?? null;
    }
    $monthlyPct = _swarmz_pctOf($monthlyRemForPct, $usage['monthlyCredits'] ?? null);
    $cloudPct = _swarmz_pctOf($usage['cloudGrantRemaining'] ?? null, $usage['cloudGrant'] ?? null);
    $aiPct = _swarmz_pctOf($usage['aiGrantRemaining'] ?? null, $usage['aiGrant'] ?? null);

    return [
        'templatefile' => 'overview',
        'vars' => [
            'tenantId'             => $tenantId,
            'serviceId'            => $serviceId,
            'dashboardUrl'         => $dashboardUrl,
            'ssoUrl'               => $ssoUrl,
            'usage'                => $usage,
            'creditsUsed'          => $usage['creditsUsed']   ?? 0,
            'projectsCount'        => $usage['projectsCount'], // may be null — template handles it
            // Plan limits (W6.6): null = unlimited (0-sentinel already applied).
            'domainsCount'         => $usage['domainsCount']   ?? null,
            'domainsLimit'         => $usage['domainsLimit']   ?? null,
            'publishedCount'       => $usage['publishedCount'] ?? null,
            'publishedLimit'       => $usage['publishedLimit'] ?? null,
            'customDomainsEnabled' => $usage['customDomainsEnabled'] ?? true,
            // Three SEPARATE credit pools (never merged). Values come from the
            // live per-pool balances + plan caps in the platform-usage API
            // response — never from any locally-configured option.
            // 1) Free — grant cadence comes from the plan (freeKind); the
            //    numbers are pre-resolved + pre-formatted in PHP so the
            //    template does zero arithmetic.
            'freeKind'             => $freeCard['kind'],
            'freeRemainingFmt'     => $freeCard['remaining'],
            'freeTotalFmt'         => $freeCard['total'],
            // Progress-bar fills (0-100 remaining share; null = no bar).
            'freePct'              => $freePct,
            'monthlyPct'           => $monthlyPct,
            'cloudPct'             => $cloudPct,
            'aiPct'                => $aiPct,
            // Legacy fields — kept for field-modified templates.
            'freeDaily'            => $usage['freeDaily']        ?? null,  // null = unlimited
            'freeDailyUsed'        => $usage['freeDailyUsed']    ?? null,
            'freeMonthlyCap'       => $usage['freeMonthlyCap']   ?? null,
            // 2) Monthly — paid grant per cycle (+ rollover pool).
            'monthlyCredits'       => $usage['monthlyCredits']   ?? 0,
            'monthlyUsed'          => $usage['monthlyUsed']      ?? null,
            'monthlyRemaining'     => $usage['monthlyRemaining'] ?? null,
            'rolloverCredits'      => $usage['rolloverCredits']  ?? null,
            'rolloverMonths'       => $usage['rolloverMonths']   ?? 0,
            // 3) Cloud credits — separate lane; cadence from the plan
            //    ('monthly' | 'one_time' | 'none'; null = legacy per-cycle).
            'cloudGrant'           => $usage['cloudGrant']          ?? 0,
            'cloudGrantRemaining'  => $usage['cloudGrantRemaining'] ?? 0,
            'cloudMode'            => $usage['cloudMode']           ?? null,
            // 4) AI credits — separate lane; cadence from the plan.
            'aiGrant'              => $usage['aiGrant']             ?? 0,
            'aiGrantRemaining'     => $usage['aiGrantRemaining']    ?? 0,
            'aiMode'               => $usage['aiMode']              ?? null,
            // Where the credit numbers came from ('live' | 'api') — for sub-labels.
            'creditsSource'        => $usage['creditsSource']    ?? 'api',
            // Host-configurable presentation, set in the Reseller Console addon
            // module (falls back to sensible defaults when the addon is absent).
            'editorButtonLabel' => Helpers::editorButtonLabel(),
            'creditTerm'        => Helpers::creditTerm(),
            'supportUrl'        => Helpers::supportUrl(),
            // Credit packs (v1.11.0): the addon-store link, or '' when the host
            // has no mapped, orderable pack assigned to this product. The
            // template renders a quiet "buy more" link only when non-empty.
            'buyCreditsUrl'     => Helpers::creditPackStoreUrl($serviceId),
        ],
    ];
}

// =========================================================================
// Optional: TestConnection — wired so the WHMCS server form's "Test Connection"
// button does a real liveness call against /platform-usage (read-only).
// =========================================================================

/**
 * Server connectivity test from the WHMCS Servers form.
 *
 * Strategy: hit /platform-sso with a deliberately-non-existent tenant_id.
 * The endpoint requires a valid bearer key BEFORE it looks up the tenant, so
 * the auth check fires first. We then expect a 404 (tenant_not_found) or
 * similar "key valid, tenant missing" reply — that proves connectivity AND
 * key validity in a single round-trip without any side effects.
 *
 * We deliberately avoid /platform-usage here: that endpoint is read-only
 * but its underlying view is currently incomplete on the deployed server,
 * so it's not a reliable liveness probe.
 *
 * Accepted "good" outcomes for the smoke probe:
 *   - HTTP 404 tenant_not_found  → auth ok, tenant missing (expected)
 *   - HTTP 410 terminated        → auth ok, tenant was once real (unlikely)
 *   - HTTP 400 missing_fields    → auth ok, body shape rejected (also fine)
 *
 * Bad outcomes:
 *   - HTTP 401 unauthorized      → bad key
 *   - HTTP 5xx                   → server outage
 *   - cURL transport error       → network / DNS / TLS
 *
 * @param array $params
 * @return array{success: bool, error?: string}
 */
function swarmz_TestConnection(array $params)
{
    $api = null;
    try {
        $api = Helpers::makeApiClient($params);
        // Use a zero UUID — a syntactically-valid tenant_id that cannot
        // exist in the wild. The endpoint resolves it through workspaces.id
        // (UUID column), so this short-circuits to "not found".
        $body = ['tenant_id' => '00000000-0000-0000-0000-000000000000'];
        try {
            $result = $api->postPlatform('platform-sso', $body);
            _swarmz_logModuleCall(
                'TestConnection',
                ['baseUrl' => $api->getBaseUrl()],
                $result['body'],
                $api->maskedKey()
            );
            return ['success' => true];
        } catch (SwarmzApiException $e) {
            $status = $e->getStatusCode();
            $code   = $e->getErrorCode();
            // 4xx with a non-auth code → key is valid, tenant lookup failed (expected).
            if ($status >= 400 && $status < 500 && $code !== 'unauthorized') {
                _swarmz_logModuleCall(
                    'TestConnection',
                    ['baseUrl' => $api->getBaseUrl()],
                    ['note' => sprintf('Expected %d %s — key is valid.', $status, $code)],
                    $api->maskedKey()
                );
                return ['success' => true];
            }
            // 401 / 403 / 5xx → propagate as failure.
            throw $e;
        }
    } catch (\Throwable $e) {
        _swarmz_logModuleCall(
            'TestConnection.Error',
            ['baseUrl' => $api ? $api->getBaseUrl() : null],
            ['error' => $e->getMessage()],
            $api ? $api->maskedKey() : ''
        );
        return [
            'success' => false,
            'error'   => Helpers::formatError($e, $api ? $api->maskedKey() : null),
        ];
    }
}

// =========================================================================
// Internal helpers (file-local).
// =========================================================================

/**
 * Run a simple POST-and-done lifecycle action (suspend/unsuspend/terminate).
 *
 * @param string $hookName e.g. "SuspendAccount"
 * @param string $endpoint e.g. "platform-suspend"
 * @param array  $params
 * @return string "success" or error string
 */
function _swarmz_simpleLifecycle(string $hookName, string $endpoint, array $params): string
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $externalRef = Helpers::buildExternalRef($serviceId);
    $tenantId = Helpers::getTenantId($serviceId);

    $api = null;
    try {
        $api = Helpers::makeApiClient($params);

        $body = ['external_ref' => $externalRef];
        if ($tenantId !== null) {
            $body['tenant_id'] = $tenantId;
        }

        $result = $api->postPlatform($endpoint, $body);
        _swarmz_logModuleCall($hookName, $body, $result['body'], $api->maskedKey());

        return 'success';
    } catch (SwarmzApiException $e) {
        // NOTE: platform-terminate is idempotent — it returns 200 { already: true }
        // for a missing/already-gone tenant, never 404. (An earlier 404-is-benign
        // guard here was dead code and was removed in v1.7.0.) A 410 on
        // suspend/unsuspend (terminated workspace) still surfaces as a normal error.
        _swarmz_logModuleCall($hookName . '.Error', $params, ['error' => $e->getMessage(), 'status' => $e->getStatusCode()], $api->maskedKey());
        return Helpers::formatError($e, $api->maskedKey());
    } catch (\Throwable $e) {
        _swarmz_logModuleCall($hookName . '.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        return Helpers::formatError($e, $api ? $api->maskedKey() : null);
    }
}

// _swarmz_planRefresh was removed in v1.6.0: renewal-boundary refreshes are
// owned entirely by hooks.php (InvoicePaid + the daily safety net); the
// mid-cycle ChangePackage path is prorated server-side inside platform-plan.

/**
 * Send the browser to $url with a 302 and stop. Used by the swarmz_launch
 * custom action so each launch is a fresh, un-suppressed navigation.
 *
 * Only http(s) URLs are honoured (platform-sso always returns an absolute
 * https URL); anything else is refused rather than emitted as a header.
 * Outside a live WHMCS request (CLI/test harness) headers/exit are skipped so
 * the caller can assert on the return value instead.
 *
 * @param string $url
 */
function _swarmz_redirect(string $url): void
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return;
    }

    // In the CLI/test harness there is no WHMCS request to redirect; leave the
    // navigation to the caller's return value.
    if (!defined('WHMCS') || php_sapi_name() === 'cli') {
        return;
    }

    if (!headers_sent()) {
        header('Location: ' . $url, true, 302);
    }
    exit;
}

/**
 * Run /platform-sso and return the WHMCS-shaped result array.
 *
 * @param array $params
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function _swarmz_doSso(array $params): array
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $externalRef = Helpers::buildExternalRef($serviceId);
    $tenantId = Helpers::getTenantId($serviceId);

    $api = null;
    try {
        $api = Helpers::makeApiClient($params);

        $body = ['external_ref' => $externalRef];
        if ($tenantId !== null) {
            $body['tenant_id'] = $tenantId;
        }

        $result = $api->postPlatform('platform-sso', $body);
        $resp = $result['body'];

        _swarmz_logModuleCall('SSO', $body, $resp, $api->maskedKey());

        if (!empty($resp['redirectTo'])) {
            return [
                'success'    => true,
                'redirectTo' => (string) $resp['redirectTo'],
            ];
        }
        return [
            'success'  => false,
            'errorMsg' => 'Swarmz: SSO endpoint did not return a redirectTo URL.',
        ];
    } catch (SwarmzApiException $e) {
        _swarmz_logModuleCall('SSO.Error', $params, ['error' => $e->getMessage(), 'status' => $e->getStatusCode()], $api ? $api->maskedKey() : '');
        return [
            'success'  => false,
            'errorMsg' => _swarmz_ssoFriendlyError($e),
        ];
    } catch (\Throwable $e) {
        _swarmz_logModuleCall('SSO.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        return [
            'success'  => false,
            'errorMsg' => Helpers::formatError($e, $api ? $api->maskedKey() : null),
        ];
    }
}

/**
 * Translate a platform-sso API refusal into copy fit for the CUSTOMER's screen.
 * The raw `{error, reason}` pair ("suspended", "tenant_not_found") is developer
 * vocabulary; the person clicking the launch button is the host's end customer,
 * so the message must say what to do next — and stay unbranded (no "Swarmz").
 * The raw code still lands in the Module Log via the caller for diagnostics.
 *
 * @param SwarmzApiException $e
 * @return string
 */
function _swarmz_ssoFriendlyError(SwarmzApiException $e): string
{
    switch ($e->getStatusCode()) {
        case 404:
            return 'Your workspace is still being set up. Please try again in a '
                . 'minute — if this keeps happening, contact support.';
        case 409:
            if ($e->getErrorCode() === 'account_inactive') {
                return 'The editor is temporarily unavailable. Please contact support.';
            }
            return 'Your service is currently suspended, so the editor cannot be '
                . 'opened. Please check your billing status or contact support.';
        case 410:
            return 'This service has been cancelled and its workspace no longer '
                . 'exists. Contact support if you believe this is a mistake.';
        case 401:
        case 403:
            return 'The editor could not be opened due to a configuration issue '
                . 'on our side. Please contact support.';
        default:
            return 'The editor could not be opened just now. Please try again — '
                . 'if this keeps happening, contact support.';
    }
}

/**
 * Wrapper around WHMCS's logModuleCall that redacts the bearer key.
 *
 * @param string $action
 * @param mixed  $request
 * @param mixed  $response
 * @param string $maskedKey If non-empty, replaced wherever it appears in the log strings.
 */
function _swarmz_logModuleCall(string $action, $request, $response, string $maskedKey = ''): void
{
    if (!function_exists('logModuleCall')) {
        return; // Module loaded outside WHMCS context (e.g. CLI tests).
    }

    // Any string we KNOW could contain a key — there shouldn't be any, but be paranoid.
    $replaceVars = ['sk_live_', 'sk_test_', 'Bearer '];
    if ($maskedKey !== '') {
        $replaceVars[] = $maskedKey;
    }

    try {
        logModuleCall(
            'swarmz',
            $action,
            $request,
            $response,
            $response,
            $replaceVars
        );
    } catch (\Throwable $e) {
        // Logging must never break the module.
    }
}
