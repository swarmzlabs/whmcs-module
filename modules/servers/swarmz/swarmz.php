<?php
/**
 * Swarmz WHMCS Provisioning Module
 *
 * Thin server module for reselling swarmz workspaces from WHMCS. Talks only to
 * the swarmz public enterprise API (api.swarmz.net by default) — all business
 * logic is server-side.
 *
 * Required endpoints (single-purpose, see reseller-functions-rewrite.md §9):
 *   POST /functions/v1/enterprise-create
 *   POST /functions/v1/enterprise-plan
 *   POST /functions/v1/enterprise-topup
 *   POST /functions/v1/enterprise-suspend
 *   POST /functions/v1/enterprise-unsuspend
 *   POST /functions/v1/enterprise-terminate
 *   POST /functions/v1/enterprise-usage
 *   POST /functions/v1/enterprise-sso
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
// Order matters: each maps to configoption1..7 (see Helpers).
// =========================================================================

/**
 * Per-product configuration options. Maps 1:1 to the entitlements schema
 * (reseller-functions-rewrite.md §10). Empty string = unlimited where allowed.
 *
 * @return array
 */
function swarmz_ConfigOptions()
{
    return [
        // 1
        'credits_per_day' => [
            'FriendlyName' => 'Credits per day',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '5',
            'Description'  => 'Daily AI credit budget per workspace. Leave empty for unlimited.',
        ],
        // 2
        'monthly_credit_cap' => [
            'FriendlyName' => 'Monthly credit cap',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '',
            'Description'  => 'Optional hard monthly ceiling. Leave empty for none.',
        ],
        // 3
        'max_projects' => [
            'FriendlyName' => 'Max projects',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '',
            'Description'  => 'Optional project count cap. Leave empty for unlimited.',
        ],
        // 4
        'max_custom_domains' => [
            'FriendlyName' => 'Max custom domains',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '0',
            'Description'  => 'Optional custom-domain cap (each domain costs ~$0.10/mo). Default 0.',
        ],
        // 5
        'max_compute_size' => [
            'FriendlyName' => 'Max compute size',
            'Type'         => 'dropdown',
            'Options'      => 'nano,micro,small,medium,large,xlarge,2xl,4xl',
            'Default'      => 'nano',
            'Description'  => 'Locks the editor compute selector. Provisioning still uses Nano scale-to-zero.',
        ],
        // 6
        'cloud_budget_cap' => [
            'FriendlyName' => 'Cloud budget cap (USD)',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '',
            'Description'  => 'Optional per-workspace cloud USD ceiling. Leave empty for none.',
        ],
        // 7
        'default_credits_topup' => [
            'FriendlyName' => 'Initial credit top-up',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => '0',
            'Description'  => 'Credits granted at provisioning time (one-shot, idempotent on serviceid).',
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

        $result = $api->postEnterprise('enterprise-create', $body);

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
            return 'Swarmz: enterprise-create succeeded but no tenant_id was returned.';
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
                $topupResult = $api->postEnterprise('enterprise-topup', $topupBody);
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
    return _swarmz_simpleLifecycle('SuspendAccount', 'enterprise-suspend', $params);
}

/**
 * Resume a previously-suspended workspace.
 *
 * @param array $params
 * @return string
 */
function swarmz_UnsuspendAccount(array $params)
{
    return _swarmz_simpleLifecycle('UnsuspendAccount', 'enterprise-unsuspend', $params);
}

/**
 * Permanently delete the workspace (full teardown). No undo.
 *
 * @param array $params
 * @return string
 */
function swarmz_TerminateAccount(array $params)
{
    return _swarmz_simpleLifecycle('TerminateAccount', 'enterprise-terminate', $params);
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

        $result = $api->postEnterprise('enterprise-plan', $body);
        _swarmz_logModuleCall('ChangePackage', $body, $result['body'], $api->maskedKey());

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
 * WHMCS expects the data shape to match the metrics defined by the module
 * (Usage Billing). We return a flat associative array of metric->units; if
 * Usage Billing isn't configured, WHMCS ignores the structured fields and only
 * the disk/bw numbers (if any) are surfaced in the client area.
 *
 * @param array $params
 * @return array
 */
function swarmz_UsageUpdate(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $externalRef = Helpers::buildExternalRef($serviceId);

    $api = null;
    try {
        $api = Helpers::makeApiClient($params);

        $body = ['external_ref' => $externalRef, 'period' => 'current_month'];
        $tenantId = Helpers::getTenantId($serviceId);
        if ($tenantId !== null) {
            $body['tenant_id'] = $tenantId;
        }

        $result = $api->postEnterprise('enterprise-usage', $body);
        $resp = $result['body'];

        _swarmz_logModuleCall('UsageUpdate', $body, $resp, $api->maskedKey());

        // Normalize: API returns { ok, usage: { credits_used, credits_limit, cloud_usd, projects_count, ... } }
        $usage = isset($resp['usage']) && is_array($resp['usage']) ? $resp['usage'] : [];

        return [
            'success'        => true,
            'creditsUsed'    => $usage['credits_used']    ?? 0,
            'creditsLimit'   => $usage['credits_limit']   ?? null,
            'cloudUsd'       => $usage['cloud_usd']       ?? 0,
            'projectsCount'  => $usage['projects_count']  ?? 0,
            'domainsCount'   => $usage['domains_count']   ?? 0,
            'periodStart'    => $usage['period_start']    ?? null,
            'periodEnd'      => $usage['period_end']      ?? null,
            'raw'            => $usage,
        ];
    } catch (\Throwable $e) {
        _swarmz_logModuleCall('UsageUpdate.Error', $params, ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
        return [
            'success'  => false,
            'errorMsg' => Helpers::formatError($e, $api ? $api->maskedKey() : null),
        ];
    }
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
            'tenantId'      => $tenantId,
            'dashboardUrl'  => $dashboardUrl,
            'ssoUrl'        => $ssoUrl,
            'usage'         => $usage,
            'creditsUsed'   => $usage['creditsUsed']   ?? 0,
            'creditsLimit'  => $usage['creditsLimit']  ?? null,
            'cloudUsd'      => $usage['cloudUsd']      ?? 0,
            'projectsCount' => $usage['projectsCount'] ?? 0,
        ],
    ];
}

// =========================================================================
// Optional: TestConnection — wired so the WHMCS server form's "Test Connection"
// button does a real liveness call against /enterprise-usage (read-only).
// =========================================================================

/**
 * Server connectivity test from the WHMCS Servers form.
 *
 * @param array $params
 * @return array{success: bool, error?: string}
 */
function swarmz_TestConnection(array $params)
{
    $api = null;
    try {
        $api = Helpers::makeApiClient($params);
        // Hit /enterprise-usage with no tenant — the API will respond with the
        // account-level usage view if the key is valid (or 401 if not).
        $result = $api->postEnterprise('enterprise-usage', ['period' => 'current_month']);
        _swarmz_logModuleCall('TestConnection', ['baseUrl' => $api->getBaseUrl()], $result['body'], $api->maskedKey());
        return ['success' => true];
    } catch (\Throwable $e) {
        _swarmz_logModuleCall('TestConnection.Error', ['baseUrl' => $api ? $api->getBaseUrl() : null], ['error' => $e->getMessage()], $api ? $api->maskedKey() : '');
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
 * @param string $endpoint e.g. "enterprise-suspend"
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

        $result = $api->postEnterprise($endpoint, $body);
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
 * Run /enterprise-sso and return the WHMCS-shaped result array.
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

        $result = $api->postEnterprise('enterprise-sso', $body);
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
