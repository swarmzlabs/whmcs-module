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
        'AdminSingleSignOnLabel'   => 'Open AI Editor (admin)',
        'ListAccountsUniqueIdentifierField'       => 'tenant_id',
        'ListAccountsUniqueIdentifierDisplayName' => 'Swarmz Tenant ID',
    ];
}

// =========================================================================
// Product config options — per-product knobs the WHMCS admin sets.
//
// ORDER IS LOAD-BEARING: WHMCS maps these to configoption1..11 BY POSITION,
// and Helpers::mapConfigOptionsToEntitlements reads them positionally. Never
// reorder or remove an option — it silently remaps saved values on every
// existing product. Add new options at the END only.
//
// FriendlyNames use a "Group · field" prefix so related knobs read as clusters
// in WHMCS's flat two-column layout. Descriptions are kept to one short line;
// the full reference lives in README.md.
// =========================================================================

/**
 * Per-product configuration options. Maps 1:1 to the entitlements schema.
 * The sentinel for the count caps is: blank or 0 = unlimited.
 *
 * @return array
 */
function swarmz_ConfigOptions()
{
    return [
        // 1
        'credits_per_day' => [
            'FriendlyName' => 'Credits · free per day',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '5',
            'Description'  => 'Free AI credits each day. Blank = unlimited.',
        ],
        // 2
        'monthly_credit_cap' => [
            'FriendlyName' => 'Credits · free monthly cap',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '',
            'Description'  => 'Ceiling on free credits per month. Blank = none.',
        ],
        // 3
        'max_projects' => [
            'FriendlyName' => 'Limit · projects',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '',
            'Description'  => 'Projects they can create. 0 or blank = unlimited.',
        ],
        // 4
        'max_custom_domains' => [
            'FriendlyName' => 'Limit · custom domains',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '0',
            'Description'  => 'How many custom domains. 0 = unlimited. (Switch off with "allow custom domains" below.)',
        ],
        // 5
        'max_compute_size' => [
            'FriendlyName' => 'Compute · max size',
            'Type'         => 'dropdown',
            'Options'      => 'nano,micro,small,medium,large,xlarge,2xl,4xl',
            'Default'      => 'nano',
            'Description'  => 'Compute tier shown in the editor. Provisioning is always Nano today.',
        ],
        // 6
        'cloud_budget_cap' => [
            'FriendlyName' => 'Cloud · budget cap (USD)',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '',
            'Description'  => 'Pause the backend past this monthly USD spend. Blank = none.',
        ],
        // 7
        'default_credits_topup' => [
            'FriendlyName' => 'Credits · signup bonus',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '0',
            'Description'  => 'One-off credits granted at signup. 0 = none.',
        ],
        // 8
        'monthly_credits' => [
            'FriendlyName' => 'Credits · paid monthly',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '0',
            'Description'  => 'Paid credits added each billing cycle. 0 = none (free daily budget only).',
        ],
        // 9
        'rollover_months' => [
            'FriendlyName' => 'Credits · rollover',
            'Type'         => 'dropdown',
            // Option keys are the values stored/sent; labels are shown to the admin.
            'Options'      => [
                '0' => 'None — reset each cycle',
                '1' => 'Carry over 1 month',
                '2' => 'Carry over 2 months',
            ],
            'Default'      => '0',
            'Description'  => 'How long unused paid credits carry over before expiring.',
        ],
        // 10
        'max_published_projects' => [
            'FriendlyName' => 'Limit · published apps',
            'Type'         => 'text',
            'Size'         => '8',
            'Default'      => '0',
            'Description'  => 'How many apps can be live at once. 0 = unlimited.',
        ],
        // 11
        'custom_domains_enabled' => [
            'FriendlyName' => 'Limit · allow custom domains',
            'Type'         => 'yesno',
            'Default'      => 'on',
            'Description'  => 'Master on/off for custom domains on this plan.',
        ],
    ];
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
    $entitlements = Helpers::mapConfigOptionsToEntitlements($params);
    $whu = Helpers::buildWhu($params);
    $topup = Helpers::getDefaultCreditsTopup($params);

    $api = null;
    try {
        $api = Helpers::makeApiClient($params);

        $body = [
            'external_ref' => $externalRef,
            'whu'          => $whu,
            'entitlements' => $entitlements,
        ];

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

        // Apply the one-shot initial top-up (idempotent on the WHMCS service id).
        if ($topup > 0) {
            try {
                $topupBody = [
                    'tenant_id'       => $tenantId,
                    'external_ref'    => $externalRef,
                    'amount'          => $topup,
                    'idempotency_key' => 'create:' . $serviceId,
                ];
                $topupResult = $api->postPlatform('platform-topup', $topupBody);
                _swarmz_logModuleCall('CreateAccount.InitialTopup', $topupBody, $topupResult['body'], $api->maskedKey());
            } catch (\Throwable $e) {
                // Don't fail the whole provision because the top-up errored —
                // surface as a soft warning string instead.
                _swarmz_logModuleCall('CreateAccount.InitialTopup.Failed', ['tenant_id' => $tenantId, 'amount' => $topup], ['error' => $e->getMessage()], $api->maskedKey());
                return 'success (warning: initial credit top-up failed — ' . Helpers::formatError($e, $api->maskedKey()) . ')';
            }
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
    $entitlements = Helpers::mapConfigOptionsToEntitlements($params);

    $api = null;
    try {
        $api = Helpers::makeApiClient($params);

        $body = [
            'external_ref' => $externalRef,
            'entitlements' => $entitlements,
        ];
        $tenantId = Helpers::getTenantId($serviceId);
        if ($tenantId !== null) {
            $body['tenant_id'] = $tenantId;
        }

        $result = $api->postPlatform('platform-plan', $body);
        _swarmz_logModuleCall('ChangePackage', $body, $result['body'], $api->maskedKey());

        // Roll the credit cycle at the billing boundary. A package change is a
        // cycle event (new entitlements → reset monthly credits + apply
        // rollover), so we ask swarmz to refresh the plan anchored to the
        // service's next-due-date. Idempotent per (tenant, cycle_anchor) on the
        // server, so a same-day retry is a no-op.
        //
        // Best-effort: a refresh failure must NOT fail the package change. We
        // only have a meaningful workspace to refresh once a tenant_id exists;
        // skip silently before provisioning.
        if ($tenantId !== null) {
            _swarmz_planRefresh($api, $tenantId, Helpers::resolveCycleAnchor($params, $serviceId), 'ChangePackage');
        }

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
 * Admin-side server SSO (rare for swarmz — defers to service SSO).
 *
 * @param array $params
 * @return array{success: bool, redirectTo?: string, errorMsg?: string}
 */
function swarmz_AdminSingleSignOn(array $params)
{
    return _swarmz_doSso($params);
}

// =========================================================================
// Usage metrics — WHMCS polls this to render usage in the client area.
// =========================================================================

/**
 * Return current-period usage for the workspace.
 *
 * The live /platform-usage endpoint returns the shape:
 *   { ok: true, usage: {
 *       credits_used: number,
 *       usd_credits:  number,
 *       cloud_usd:    number,
 *       period:       { from: ISO, to: ISO, label: "current_month"|"last_month"|"ytd" },
 *       by_workspace: [{ workspace_id, credits_used, usd_credits, cloud_usd }]
 *   } }
 *
 * IMPORTANT: the endpoint resolves the workspace ONLY by tenant_id; passing
 * external_ref alone returns account-wide aggregate (a leak in this context).
 * We therefore only call it once we know the tenant_id — otherwise we return
 * a tidy "not provisioned yet" response without hitting the API.
 *
 * Pricing knobs (credits_per_day, monthly_credit_cap, max_projects, etc.) live
 * in the WHMCS product config, so we surface those locally as the "limit"
 * values rather than trying to fish them out of the usage response.
 *
 * @param array $params
 * @return array
 */
function swarmz_UsageUpdate(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $externalRef = Helpers::buildExternalRef($serviceId);
    $tenantId = Helpers::getTenantId($serviceId);

    // Local entitlement view — what the admin configured on the product. We
    // surface this even if the API call fails, so the client area still shows
    // a useful credit budget.
    $entitlements = Helpers::mapConfigOptionsToEntitlements($params);
    $creditsLimit = $entitlements['monthly_credit_cap'] ?? null;
    if ($creditsLimit === null) {
        // Fall back to daily * 30 as a soft month-ish ceiling, if a daily cap is set.
        $dailyCap = $entitlements['credits_per_day'] ?? null;
        if ($dailyCap !== null) {
            $creditsLimit = (int) $dailyCap * 30;
        }
    }

    // Plan limits surfaced to the client area (W6.6). Apply the 0 = UNLIMITED
    // sentinel for domains + published projects (decision D1): null is returned
    // for "unlimited" so the template can show "∞"/omit the denominator.
    $domainsLimit = Helpers::unlimitedSentinel($entitlements['max_custom_domains'] ?? 0);
    $publishedLimit = Helpers::unlimitedSentinel($entitlements['max_published_projects'] ?? 0);
    $customDomainsEnabled = (bool) ($entitlements['custom_domains_enabled'] ?? true);
    // Paid monthly grant (0 = none). Surfaced so the client sees their monthly
    // credit allotment distinctly from the soft cap.
    $monthlyCredits = (int) ($entitlements['monthly_credits'] ?? 0);

    // Not provisioned yet → don't hit the API; return a friendly placeholder
    // so the client area template can render without errors. The endpoint
    // does NOT resolve by external_ref alone — it would silently return the
    // entire account's aggregate, which is the wrong number to show this
    // client.
    if ($tenantId === null) {
        return [
            'success'              => true,
            'creditsUsed'          => 0,
            'creditsLimit'         => $creditsLimit,
            'monthlyCredits'       => $monthlyCredits,
            'cloudUsd'             => 0.0,
            'usdCredits'           => 0.0,
            'projectsCount'        => 0,
            'domainsCount'         => null,
            'domainsLimit'         => $domainsLimit,
            'publishedCount'       => null,
            'publishedLimit'       => $publishedLimit,
            'customDomainsEnabled' => $customDomainsEnabled,
            'periodStart'          => null,
            'periodEnd'            => null,
            'raw'                  => [],
        ];
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

        return [
            'success'              => true,
            'creditsUsed'          => isset($usage['credits_used']) ? (float) $usage['credits_used'] : 0.0,
            'creditsLimit'         => $creditsLimit,
            'monthlyCredits'       => $monthlyCredits,
            'cloudUsd'             => isset($usage['cloud_usd'])    ? (float) $usage['cloud_usd']    : 0.0,
            'usdCredits'           => isset($usage['usd_credits'])  ? (float) $usage['usd_credits']  : 0.0,
            // The live endpoint doesn't return project/domain counts; null tells
            // the template to omit the field rather than show "0".
            'projectsCount'        => null,
            'domainsCount'         => null,
            'domainsLimit'         => $domainsLimit,
            'publishedCount'       => null,
            'publishedLimit'       => $publishedLimit,
            'customDomainsEnabled' => $customDomainsEnabled,
            'periodStart'          => $period['from']  ?? null,
            'periodEnd'            => $period['to']    ?? null,
            'periodLabel'          => $period['label'] ?? null,
            'raw'                  => $usage,
        ];
    } catch (SwarmzApiException $e) {
        // `usage_read_failed` is a known soft failure — the metrics view can be
        // briefly incomplete during a server-side migration. Treat as a
        // "no data yet" outcome instead of an error so the client area still
        // renders the SSO button + budget hints.
        $isSoft = ($e->getErrorCode() === 'usage_read_failed');
        _swarmz_logModuleCall(
            $isSoft ? 'UsageUpdate.SoftUnavailable' : 'UsageUpdate.Error',
            $params,
            ['error' => $e->getMessage(), 'status' => $e->getStatusCode()],
            $api ? $api->maskedKey() : ''
        );
        // On a soft/hard failure, fall back to the daily-cron usage cache (if
        // present) so the client area still shows recent numbers rather than
        // zeros. The plan limits always come from the local entitlement view.
        $limits = [
            'creditsLimit'         => $creditsLimit,
            'monthlyCredits'       => $monthlyCredits,
            'domainsLimit'         => $domainsLimit,
            'publishedLimit'       => $publishedLimit,
            'customDomainsEnabled' => $customDomainsEnabled,
        ];
        if ($isSoft) {
            return _swarmz_usageFromCacheOrZero($serviceId, $limits, true, null, 'metrics_unavailable');
        }
        return _swarmz_usageFromCacheOrZero(
            $serviceId,
            $limits,
            false,
            Helpers::formatError($e, $api ? $api->maskedKey() : null)
        );
    } catch (\Throwable $e) {
        _swarmz_logModuleCall('UsageUpdate.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        $limits = [
            'creditsLimit'         => $creditsLimit,
            'monthlyCredits'       => $monthlyCredits,
            'domainsLimit'         => $domainsLimit,
            'publishedLimit'       => $publishedLimit,
            'customDomainsEnabled' => $customDomainsEnabled,
        ];
        return _swarmz_usageFromCacheOrZero(
            $serviceId,
            $limits,
            false,
            Helpers::formatError($e, $api ? $api->maskedKey() : null)
        );
    }
}

/**
 * Build a UsageUpdate result from the daily-cron usage cache when a live read
 * failed, falling back to zeros if there is no cache. Always carries the
 * locally-known plan limits so the template renders a useful budget view.
 *
 * @param int                $serviceId
 * @param array<string,mixed> $limits  { creditsLimit, monthlyCredits, domainsLimit, publishedLimit, customDomainsEnabled }
 * @param bool               $success
 * @param string|null        $errorMsg
 * @param string|null        $note
 * @return array
 */
function _swarmz_usageFromCacheOrZero(int $serviceId, array $limits, bool $success, ?string $errorMsg = null, ?string $note = null): array
{
    $cache = Helpers::getCachedUsage($serviceId);
    $out = [
        'success'              => $success,
        'creditsUsed'          => $cache !== null ? (float) $cache['credits'] : 0.0,
        'creditsLimit'         => $limits['creditsLimit'] ?? null,
        'monthlyCredits'       => $limits['monthlyCredits'] ?? 0,
        'cloudUsd'             => $cache !== null ? (float) $cache['cloud'] : 0.0,
        'usdCredits'           => $cache !== null ? (float) $cache['ai'] : 0.0,
        'projectsCount'        => null,
        'domainsCount'         => null,
        'domainsLimit'         => $limits['domainsLimit'] ?? null,
        'publishedCount'       => null,
        'publishedLimit'       => $limits['publishedLimit'] ?? null,
        'customDomainsEnabled' => $limits['customDomainsEnabled'] ?? true,
        'periodStart'          => null,
        'periodEnd'            => null,
        'periodLabel'          => null,
        'raw'                  => [],
    ];
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
    // WHMCS will call swarmz_AdminSingleSignOn() for us when the admin uses
    // the standard SSO button, but supplying a direct AdminLink is the way to
    // expose a custom CTA. We render a tiny form that posts to the WHMCS
    // single-sign-on endpoint.
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

    $ssoUrl = 'clientarea.php?action=productdetails&id=' . $serviceId . '&dosinglesignon=1';

    // Pull usage (best-effort; on failure, render placeholders).
    $usage = swarmz_UsageUpdate($params);

    return [
        'templatefile' => 'overview',
        'vars' => [
            'tenantId'             => $tenantId,
            'dashboardUrl'         => $dashboardUrl,
            'ssoUrl'               => $ssoUrl,
            'usage'                => $usage,
            'creditsUsed'          => $usage['creditsUsed']   ?? 0,
            'creditsLimit'         => $usage['creditsLimit']  ?? null,
            'monthlyCredits'       => $usage['monthlyCredits'] ?? 0,
            'cloudUsd'             => $usage['cloudUsd']      ?? 0,
            'usdCredits'           => $usage['usdCredits']    ?? 0,
            'projectsCount'        => $usage['projectsCount'], // may be null — template handles it
            // Plan limits (W6.6): null = unlimited (0-sentinel already applied).
            'domainsCount'         => $usage['domainsCount']   ?? null,
            'domainsLimit'         => $usage['domainsLimit']   ?? null,
            'publishedCount'       => $usage['publishedCount'] ?? null,
            'publishedLimit'       => $usage['publishedLimit'] ?? null,
            'customDomainsEnabled' => $usage['customDomainsEnabled'] ?? true,
            // Host-configurable presentation, set in the Reseller Console addon
            // module (falls back to sensible defaults when the addon is absent).
            'editorButtonLabel' => Helpers::editorButtonLabel(),
            'creditTerm'        => Helpers::creditTerm(),
            'showAiSpend'       => Helpers::showAiSpend(),
            'showCloudSpend'    => Helpers::showCloudSpend(),
            'supportUrl'        => Helpers::supportUrl(),
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
        // 404 tenant_not_found on terminate is benign — the account is already gone.
        if ($hookName === 'TerminateAccount' && $e->getStatusCode() === 404) {
            _swarmz_logModuleCall($hookName . '.AlreadyGone', ['tenant_id' => $tenantId], ['note' => '404 from API — treating as no-op'], $api->maskedKey());
            return 'success';
        }
        _swarmz_logModuleCall($hookName . '.Error', $params, ['error' => $e->getMessage(), 'status' => $e->getStatusCode()], $api->maskedKey());
        return Helpers::formatError($e, $api->maskedKey());
    } catch (\Throwable $e) {
        _swarmz_logModuleCall($hookName . '.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        return Helpers::formatError($e, $api ? $api->maskedKey() : null);
    }
}

/**
 * Best-effort call to /platform-plan-refresh. Rolls the workspace's credit
 * cycle (reset monthly credits + apply rollover) anchored at $cycleAnchor.
 *
 * Deliberately swallows every failure: a refresh is an enhancement on top of
 * the entitlement push, and the endpoint may not yet be deployed on older
 * servers (it returns 404 there) — neither case should fail the caller.
 *
 * @param Api    $api
 * @param string $tenantId
 * @param string $cycleAnchor ISO date
 * @param string $context     Calling hook name, for the log line.
 */
function _swarmz_planRefresh(Api $api, string $tenantId, string $cycleAnchor, string $context): void
{
    try {
        $result = $api->planRefresh($tenantId, $cycleAnchor, true);
        _swarmz_logModuleCall(
            $context . '.PlanRefresh',
            ['tenant_id' => $tenantId, 'cycle_anchor' => $cycleAnchor],
            $result['body'],
            $api->maskedKey()
        );
    } catch (SwarmzApiException $e) {
        // 404 = endpoint not deployed yet (graceful no-op); anything else is a
        // soft warning. Either way we don't propagate.
        _swarmz_logModuleCall(
            $context . '.PlanRefresh.Skipped',
            ['tenant_id' => $tenantId, 'cycle_anchor' => $cycleAnchor],
            ['note' => 'plan-refresh unavailable', 'status' => $e->getStatusCode(), 'error' => $e->getErrorCode()],
            $api->maskedKey()
        );
    } catch (\Throwable $e) {
        _swarmz_logModuleCall(
            $context . '.PlanRefresh.Skipped',
            ['tenant_id' => $tenantId, 'cycle_anchor' => $cycleAnchor],
            ['error' => $e->getMessage()],
            $api->maskedKey()
        );
    }
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
    } catch (\Throwable $e) {
        _swarmz_logModuleCall('SSO.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        return [
            'success'  => false,
            'errorMsg' => Helpers::formatError($e, $api ? $api->maskedKey() : null),
        ];
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
