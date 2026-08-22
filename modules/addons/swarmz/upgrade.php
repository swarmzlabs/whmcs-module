<?php
/**
 * Swarmz upgrade deep link — public endpoint.
 *
 *   GET ?intent=<code>  → signs the customer into this WHMCS and lands them on
 *                         the upgrade page for their Swarmz service.
 *
 * Flow (v1.23.0):
 *   1. The customer clicks a plan in the plan picker inside their white-label
 *      Swarmz editor. The platform mints a ONE-TIME intent for that customer's
 *      own workspace (2-minute expiry) and redirects the browser here.
 *   2. This endpoint exchanges the intent with the platform
 *      (`platform-upgrade-intent`, authenticated with this install's Bearer
 *      API key). The platform answers with the workspace's service reference
 *      ("whmcs:<serviceid>") only if the intent is valid, unexpired, unused,
 *      and was minted for THIS platform account — anything else is a 404.
 *   3. We look up the service → owning client, mint a WHMCS single sign-on
 *      token (`CreateSsoToken`, WHMCS 7.10+) with a custom redirect to
 *      `upgrade.php?type=package&id=<serviceid>`, and 302 the browser to it.
 *      v1.25.0: when the intent names a plan this install sells (the platform
 *      returns `plan_code`), the redirect carries `step=2&pid=<product>&
 *      billingcycle=<cycle>` — exactly what the stock "Choose Product" form
 *      posts — so the customer lands on WHMCS's checkout step for that
 *      product, not the product list (Helpers::resolveUpgradeTarget).
 *      If `CreateSsoToken` is unavailable/refused we 302 to the service's
 *      client-area page instead — WHMCS prompts for login and continues.
 *
 * Security posture: this file never receives, stores or returns WHMCS
 * credentials. The only thing a leaked intent can yield is an SSO for the
 * service it was minted for, and only once, within two minutes, via an API
 * exchange bound to this install's own key. The SSO token itself is WHMCS's
 * own one-minute, single-use token.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

use WHMCS\Database\Capsule;

require __DIR__ . '/../../../init.php';

$swarmzServerLib = __DIR__ . '/../../servers/swarmz/lib';
require_once $swarmzServerLib . '/Exceptions.php';
require_once $swarmzServerLib . '/Api.php';
require_once $swarmzServerLib . '/Helpers.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/**
 * Render a minimal, neutral error page (no Swarmz marks — this is the host's
 * domain) and stop.
 */
$fail = static function (int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Upgrade</title>'
        . '<style>body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;display:grid;place-items:center;min-height:100vh;color:#222;background:#fafafa}'
        . '.c{max-width:420px;padding:32px;text-align:center}a{color:inherit}</style>'
        . '<div class="c"><p>' . $safe . '</p>'
        . '<p><a href="clientarea.php">Go to your client area</a></p></div>';
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $fail(405, 'This link only supports GET.');
}

$intent = isset($_GET['intent']) ? (string) $_GET['intent'] : '';
// Opaque 32-byte base64url code, exactly as the platform mints it.
if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $intent)) {
    $fail(400, 'This upgrade link is not valid.');
}

// API key + base URL: the Reseller Console addon settings (or any server's
// Password field) — the same resolution every other platform call uses.
try {
    $api = \WHMCS\Module\Server\Swarmz\Helpers::makeApiClient([]);
} catch (\Throwable $e) {
    $fail(503, 'Upgrades are not available right now. Please contact support.');
}

$maskVars = ['sk_live_', 'sk_test_', 'Bearer ', $api->maskedKey()];

// 1) Exchange the intent for the service reference.
try {
    $result = $api->verifyUpgradeIntent($intent);
} catch (\Throwable $e) {
    if (function_exists('logModuleCall')) {
        logModuleCall('swarmz', 'UpgradeIntent', ['intent' => '[redacted]'], $e->getMessage(), $e->getMessage(), $maskVars);
    }
    $fail(502, 'We could not verify this upgrade link. Please try again from your editor.');
}
$body = isset($result['body']) && is_array($result['body']) ? $result['body'] : [];
$statusCode = (int) ($result['statusCode'] ?? 0);
if (function_exists('logModuleCall')) {
    logModuleCall('swarmz', 'UpgradeIntent', ['intent' => '[redacted]'], $body, $body, $maskVars);
}
$externalRef = isset($body['external_ref']) ? (string) $body['external_ref'] : '';
if ($statusCode < 200 || $statusCode >= 300 || !preg_match('/^whmcs:(\d+)$/', $externalRef, $m)) {
    // Expired, replayed, or not ours.
    $fail(404, 'This upgrade link has expired. Please open the plan picker again from your editor.');
}
$serviceId = (int) $m[1];

// 2) Resolve the service → owning client. Only active/suspended services can
//    upgrade; anything else goes to the client area.
try {
    $service = Capsule::table('tblhosting')->where('id', $serviceId)->first(['id', 'userid', 'domainstatus']);
} catch (\Throwable $e) {
    $service = null;
}
if (!$service || (int) $service->userid <= 0) {
    $fail(404, 'We could not find the service for this upgrade link.');
}
$clientId = (int) $service->userid;

$systemUrl = '';
try {
    $systemUrl = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/');
} catch (\Throwable $e) {
    $systemUrl = isset($GLOBALS['CONFIG']['SystemURL']) ? rtrim((string) $GLOBALS['CONFIG']['SystemURL'], '/') : '';
}
$abs = static function (string $path) use ($systemUrl): string {
    return (preg_match('#^https?://#i', $systemUrl) ? $systemUrl . '/' : '') . $path;
};

// 3) Single sign-on straight onto the upgrade flow (WHMCS 7.10+). Relative
//    paths with query strings are supported by sso:custom_redirect. When the
//    platform names the plan the customer picked and this install sells it,
//    skip the product list and land on WHMCS's checkout step for that product
//    (step=2 + pid + billingcycle — the stock "Choose Product" request, which
//    upgrade.php reads from $_REQUEST). Otherwise the product list, where
//    WHMCS shows its own truth.
$planCode = isset($body['plan_code']) && is_string($body['plan_code']) ? trim($body['plan_code']) : '';
$target = null;
// Same shape the platform accepts for plan codes (leading _ / - included).
if ($planCode !== '' && preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,63}$/', $planCode)) {
    $target = \WHMCS\Module\Server\Swarmz\Helpers::resolveUpgradeTarget($serviceId, $planCode);
}
$upgradePath = 'upgrade.php?type=package&id=' . $serviceId;
if ($target !== null) {
    $upgradePath .= '&step=2&pid=' . (int) $target['pid']
        . '&billingcycle=' . rawurlencode((string) $target['billingcycle']);
}
$redirect = '';
try {
    $sso = localAPI('CreateSsoToken', [
        'client_id'         => $clientId,
        'destination'       => 'sso:custom_redirect',
        'sso_redirect_path' => $upgradePath,
    ]);
    if (function_exists('logModuleCall')) {
        $logged = $sso;
        if (isset($logged['access_token'])) {
            $logged['access_token'] = '[redacted]';
        }
        if (isset($logged['redirect_url'])) {
            $logged['redirect_url'] = '[redacted]';
        }
        logModuleCall('swarmz', 'UpgradeSso', ['client_id' => $clientId, 'service_id' => $serviceId, 'path' => $upgradePath], $logged, $logged, $maskVars);
    }
    if (isset($sso['result']) && $sso['result'] === 'success' && !empty($sso['redirect_url'])) {
        $redirect = (string) $sso['redirect_url'];
    }
} catch (\Throwable $e) {
    $redirect = '';
}

if ($redirect !== '' && preg_match('#^https?://#i', $redirect)) {
    header('Location: ' . $redirect, true, 302);
    exit;
}

// Fallback: no SSO available (older WHMCS / SSO disabled for this client) —
// land on the service page; WHMCS asks the customer to sign in first.
header('Location: ' . $abs('clientarea.php?action=productdetails&id=' . $serviceId), true, 302);
exit;
