<?php
/**
 * Swarmz WHMCS Module — Frictionless (express) onboarding.
 *
 * Turns a Prompt Box submission into a signed-in, provisioning workspace in
 * one server round-trip: a WHMCS client is created from just an email +
 * password (AddClient, skipvalidation), a $0 order on the widget's product
 * is placed and accepted (AddOrder + AcceptOrder, autosetup), the visitor's
 * prompt is bound to the resulting service BEFORE provisioning runs (so
 * CreateAccount's normal pendingPromptForService() lookup finds it), and a
 * fresh platform-sso redirect sends the visitor straight into the builder.
 *
 * Gated behind Helpers::expressSignupEnabled() (Reseller Console → Prompt
 * Box → Frictionless onboarding), DEFAULT OFF. promptbox.php's a=express
 * branch is a thin HTTP adapter around run() below: it decodes the request,
 * calls run(), and translates the result into the JSON contract documented
 * there. Every dependency on the outside world — WHMCS's localAPI() and the
 * platform-sso HTTP call — is injected via $ctx so this class (and the whole
 * sequence) can be driven offline by test/express-harness.php against real
 * PromptBox/Helpers code, with only those two boundaries faked.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Addon\Swarmz;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/PromptBox.php';

// Reuse the provisioning (server) module's Api client + the settings readers
// it shares with the console (same optional cross-module dependency
// Console.php has, same relative path). Absent server module → the class
// below still loads; expressSignupEnabled() then has no class to read from,
// so the gate stays closed rather than fataling.
$swarmzServerLib = __DIR__ . '/../../../servers/swarmz/lib';
if (is_file($swarmzServerLib . '/Api.php')) {
    require_once $swarmzServerLib . '/Exceptions.php';
    require_once $swarmzServerLib . '/Api.php';
    require_once $swarmzServerLib . '/Helpers.php';
}

class ExpressSignup
{
    /** HTTP status the public endpoint sends for each error code run() returns. */
    const HTTP_STATUS = [
        'express_disabled'    => 403,
        'empty_prompt'        => 422,
        'unknown_product'     => 422,
        'invalid_email'       => 422,
        'weak_password'       => 422,
        'tos_required'        => 422,
        'rate_limited'        => 429,
        'storage_unavailable' => 422,
        'account_exists'      => 409,
        'signup_failed'       => 422,
        'order_failed'        => 422,
    ];

    /**
     * Run the express-signup flow.
     *
     * @param array $input Decoded request body PLUS 'ip' (the adapter reads
     *                      REMOTE_ADDR — never trust an IP from the body
     *                      itself): { prompt, pid, email, password, tos?, ip }.
     * @param array $ctx   Injectable callables, all optional:
     *                        'localApi' => function(string $action, array $params): array
     *                        'sso'      => function(string $externalRef): ?string
     *                      Missing keys fall back to the real WHMCS localAPI()
     *                      and a real platform-sso mint via the addon's
     *                      configured API key.
     * @return array {ok:true, redirect:string} or {ok:false, error:string}
     *         (error is one of the keys of self::HTTP_STATUS).
     */
    public static function run(array $input, array $ctx = []): array
    {
        try {
            return self::execute($input, $ctx + self::defaultContext());
        } catch (\Throwable $e) {
            // A stray fault anywhere below must never surface as a raw 500 to
            // an unauthenticated public endpoint.
            self::log('ExpressSignup.Fatal', [], ['error' => $e->getMessage()]);
            return self::fail('signup_failed');
        }
    }

    /**
     * @param array $input
     * @param array $ctx    Already merged with the real-implementation defaults.
     * @return array
     */
    private static function execute(array $input, array $ctx): array
    {
        $serverHelpersAvailable = class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Helpers');

        // a. Gate — server-side, never UI-only. No server module → no class
        // to read the setting from → treated as off.
        if (!$serverHelpersAvailable || !\WHMCS\Module\Server\Swarmz\Helpers::expressSignupEnabled()) {
            return self::fail('express_disabled');
        }

        // b. Validate.
        $prompt = isset($input['prompt']) && is_string($input['prompt']) ? trim($input['prompt']) : '';
        if ($prompt === '' || self::charLen($prompt) > PromptBox::PROMPT_MAX_CHARS) {
            return self::fail('empty_prompt');
        }

        $pid = isset($input['pid']) && is_numeric($input['pid']) ? (int) $input['pid'] : 0;
        if ($pid <= 0 || !PromptBox::isSwarmzProduct($pid)) {
            return self::fail('unknown_product');
        }

        $email = isset($input['email']) && is_string($input['email']) ? trim($input['email']) : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::fail('invalid_email');
        }

        $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
        // Floor (WHMCS-side strength is bypassed by skipvalidation, so enforce
        // our own) AND ceiling (never relay an unbounded blob into a live
        // AddClient call — mirrors PROMPT_MAX_CHARS on the prompt). WHMCS 9.0
        // itself caps new-user passwords at 100 chars; 256 is a safe headroom.
        $passLen = strlen($password);
        if ($passLen < 8 || $passLen > 256) {
            return self::fail('weak_password');
        }

        $tosUrl = \WHMCS\Module\Server\Swarmz\Helpers::expressTosUrl();
        if ($tosUrl !== '' && empty($input['tos'])) {
            return self::fail('tos_required');
        }

        $ip = isset($input['ip']) && is_string($input['ip']) ? $input['ip'] : '';

        // c. Rate limit — a stricter, separate ceiling than the plain intent
        // endpoint (an express attempt also hits the WHMCS client DB and can
        // create a client + an order). Counted from a dedicated attempt log,
        // NOT from intent rows: an intent is written only on a *successful*
        // signup, so counting those would let an attacker loop a known email
        // (which 409s before any intent is written) past the ceiling forever.
        // Record this attempt up front, before the duplicate-email API call it
        // is meant to throttle.
        try {
            $recent = PromptBox::countExpressAttempts($ip, 3600);
        } catch (\Throwable $e) {
            return self::fail('storage_unavailable');
        }
        if ($recent >= PromptBox::EXPRESS_RATE_LIMIT_PER_HOUR) {
            return self::fail('rate_limited');
        }
        PromptBox::recordExpressAttempt($ip);

        // d. Duplicate email — we deliberately do NOT auto-login an existing
        // account; the widget offers a "log in" link instead.
        $dup = $ctx['localApi']('GetClientsDetails', ['email' => $email]);
        if (is_array($dup) && ($dup['result'] ?? '') === 'success') {
            return self::fail('account_exists');
        }

        // e. Create the WHMCS client from email + password alone. Both names
        // are non-empty: skipvalidation waives most required fields but NOT
        // firstname/lastname (WHMCS enforces those unconditionally), so an
        // empty lastname would make AddClient fail. lastname carries a generic
        // placeholder the customer can correct later at their first upgrade.
        $addClientParams = [
            'email'          => $email,
            'password2'      => $password,
            'firstname'      => self::deriveFirstName($email),
            'lastname'       => 'Account',
            'skipvalidation' => true,
        ];
        $addResp = $ctx['localApi']('AddClient', $addClientParams);
        self::log('ExpressSignup.AddClient', $addClientParams, is_array($addResp) ? $addResp : []);
        $clientId = (is_array($addResp) && ($addResp['result'] ?? '') === 'success')
            ? (int) ($addResp['clientid'] ?? 0)
            : 0;
        if ($clientId <= 0) {
            // WHMCS's real failure reason is in the log line above (redacted
            // of the password); the browser gets a generic code only.
            return self::fail('signup_failed');
        }

        // f. Create the prompt intent AFTER the client exists, so an
        // abandoned validation failure never burns an intent row beyond the
        // rate check above. Best-effort: a lost prompt must never block
        // account creation — the customer still gets a working workspace,
        // just not one already building their idea.
        $token = null;
        try {
            [$intentOk, $tokenOrError] = PromptBox::createIntent($prompt, $pid, $ip);
            if ($intentOk) {
                $token = $tokenOrError;
            }
        } catch (\Throwable $e) {
            // fall through with $token === null
        }

        // g. Place the order. Free products complete with nothing to pay;
        // everything else is billed monthly from the next cycle. AddOrder
        // requires a real gateway even for a $0 order, and this endpoint has no
        // existing service to inherit one from — so a store with no active
        // gateway can't complete express signup. Fail clearly rather than let
        // WHMCS reject the order with an opaque message.
        $paymentMethod = self::resolvePaymentMethod();
        if ($paymentMethod === '') {
            self::log('ExpressSignup.NoGateway', ['clientid' => $clientId], ['note' => 'no active payment gateway configured']);
            return self::fail('order_failed');
        }
        $cycle = self::resolveBillingCycle($pid);
        $orderParams = [
            'clientid'       => $clientId,
            'paymentmethod'  => $paymentMethod,
            'pid'            => [$pid],
            'billingcycle'   => [$cycle],
            'noinvoiceemail' => true,
            'noemail'        => true,
        ];
        $orderResp = $ctx['localApi']('AddOrder', $orderParams);
        self::log('ExpressSignup.AddOrder', $orderParams, is_array($orderResp) ? $orderResp : []);
        if (!is_array($orderResp) || ($orderResp['result'] ?? '') !== 'success') {
            return self::fail('order_failed');
        }
        $orderId = (int) ($orderResp['orderid'] ?? 0);

        // h. Resolve the service id the order provisions.
        $serviceId = self::resolveServiceId($orderResp, $orderId, $clientId);
        if ($serviceId <= 0) {
            return self::fail('order_failed');
        }

        // i. Bind the prompt BEFORE provisioning. No browser session exists
        // for this journey, so the ClientAreaPage/AfterShoppingCartCheckout
        // hooks never fire — this explicit bind is what makes CreateAccount's
        // pendingPromptForService() lookup work.
        if ($token !== null) {
            try {
                PromptBox::bindToService($token, $serviceId, $orderId);
            } catch (\Throwable $e) {
                // best-effort — see (f).
            }
        }

        // j. Accept the order. autosetup forces module provisioning
        // regardless of the product's own "on payment" configuration (a $0
        // order never receives a payment event). A failure here doesn't stop
        // us: the order and client already exist, so we still try for SSO /
        // fall back to a working page rather than reporting failure.
        $acceptParams = ['orderid' => $orderId, 'autosetup' => true, 'sendemail' => true];
        $acceptResp = $ctx['localApi']('AcceptOrder', $acceptParams);
        self::log('ExpressSignup.AcceptOrder', $acceptParams, is_array($acceptResp) ? $acceptResp : []);

        // k. Verify provisioning actually produced a tenant, then mint SSO.
        $tenantId = null;
        if ($serverHelpersAvailable) {
            try {
                $tenantId = \WHMCS\Module\Server\Swarmz\Helpers::getTenantId($serviceId);
            } catch (\Throwable $e) {
                $tenantId = null;
            }
        }
        if ($tenantId !== null && $tenantId !== '') {
            $externalRef = \WHMCS\Module\Server\Swarmz\Helpers::buildExternalRef($serviceId);
            $redirect = null;
            try {
                $redirect = $ctx['sso']($externalRef);
            } catch (\Throwable $e) {
                $redirect = null;
            }
            if (is_string($redirect) && $redirect !== '') {
                return ['ok' => true, 'redirect' => $redirect];
            }
            self::log('ExpressSignup.SsoFallback', ['serviceid' => $serviceId], ['note' => 'provisioned but sso mint failed']);
        } else {
            self::log('ExpressSignup.SsoFallback', ['serviceid' => $serviceId], ['note' => 'no tenant id yet after AcceptOrder']);
        }

        // l. Fallback: the customer is logged out, but WHMCS will ask them to
        // log in with the credentials they just chose — still a working path.
        // The order exists either way, so this is still ok:true.
        $fallback = rtrim(PromptBox::systemUrl(), '/') . '/clientarea.php?action=productdetails&id=' . $serviceId;
        return ['ok' => true, 'redirect' => $fallback];
    }

    /**
     * Resolve the created service id: AddOrder's own 'productids' response
     * first (comma list — a single-pid order has exactly one), falling back
     * to the newest matching tblhosting row for installs/WHMCS versions that
     * don't return it.
     */
    private static function resolveServiceId(array $orderResp, int $orderId, int $clientId): int
    {
        if (!empty($orderResp['productids'])) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $orderResp['productids']))));
            if (!empty($ids)) {
                return $ids[0];
            }
        }
        if ($orderId <= 0) {
            return 0;
        }
        try {
            $row = Capsule::table('tblhosting')
                ->where('orderid', $orderId)
                ->where('userid', $clientId)
                ->orderBy('id', 'desc')
                ->first(['id']);
            return $row ? (int) $row->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** 'free' when the product's own paytype is free; 'monthly' otherwise. */
    private static function resolveBillingCycle(int $pid): string
    {
        try {
            $row = Capsule::table('tblproducts')->where('id', $pid)->first(['paytype']);
            $paytype = $row ? strtolower(trim((string) ($row->paytype ?? ''))) : '';
        } catch (\Throwable $e) {
            $paytype = '';
        }
        return $paytype === 'free' ? 'free' : 'monthly';
    }

    /**
     * First active payment gateway, by display order — mirrors
     * swarmz_buypack's fallback (modules/servers/swarmz/swarmz.php). There is
     * no existing service/client to read a preferred gateway from here, so
     * this is the only source.
     */
    private static function resolvePaymentMethod(): string
    {
        try {
            $gw = Capsule::table('tblpaymentgateways')->where('setting', 'name')->orderBy('order')->first(['gateway']);
            return $gw ? (string) $gw->gateway : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * A WHMCS firstname from the email's local part: strip a "+tag", keep
     * only the first character capitalised (no fabricated title-casing of a
     * dotted/underscored local part), cap at 40 characters. Never returns ''
     * — AddClient requires a non-empty firstname.
     */
    private static function deriveFirstName(string $email): string
    {
        $at = strpos($email, '@');
        $local = $at === false ? $email : substr($email, 0, $at);
        $plus = strpos($local, '+');
        if ($plus !== false) {
            $local = substr($local, 0, $plus);
        }
        $local = trim($local);
        if ($local === '') {
            $local = 'Customer';
        }
        $name = ucfirst($local);
        return function_exists('mb_substr') ? mb_substr($name, 0, 40) : substr($name, 0, 40);
    }

    private static function charLen(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }

    private static function fail(string $code): array
    {
        return ['ok' => false, 'error' => $code];
    }

    /**
     * Module-log wrapper for this flow. Strips password-shaped keys before
     * anything reaches the log — the request payloads built above (AddClient
     * in particular) carry the visitor's chosen password in cleartext, and it
     * must never land in tblmodulelog.
     */
    private static function log(string $action, array $request, array $response): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        unset($request['password'], $request['password2']);
        try {
            logModuleCall('swarmz', $action, $request, $response, $response, ['sk_live_', 'sk_test_', 'Bearer ']);
        } catch (\Throwable $e) {
            // Logging must never break signup.
        }
    }

    /**
     * Real-world implementations of the two injectable boundaries, used
     * whenever $ctx doesn't override them (i.e. always, in production).
     */
    private static function defaultContext(): array
    {
        return [
            'localApi' => static function (string $action, array $params): array {
                if (!function_exists('localAPI')) {
                    return ['result' => 'error', 'message' => 'localAPI unavailable'];
                }
                $result = localAPI($action, $params);
                return is_array($result) ? $result : ['result' => 'error', 'message' => 'unexpected localAPI response'];
            },
            'sso' => static function (string $externalRef): ?string {
                if (!class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Api')
                    || !class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Helpers')
                ) {
                    return null;
                }
                try {
                    $apiKey = trim((string) \WHMCS\Module\Server\Swarmz\Helpers::addonSetting('API Key', ''));
                    if ($apiKey === '') {
                        return null;
                    }
                    $baseUrl = trim((string) \WHMCS\Module\Server\Swarmz\Helpers::addonSetting('API Base URL', ''));
                    $baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : \WHMCS\Module\Server\Swarmz\Helpers::DEFAULT_API_BASE_URL;
                    $api = new \WHMCS\Module\Server\Swarmz\Api($apiKey, $baseUrl);
                    $result = $api->postPlatform('platform-sso', ['external_ref' => $externalRef]);
                    $redirect = $result['body']['redirectTo'] ?? null;
                    return (is_string($redirect) && $redirect !== '') ? $redirect : null;
                } catch (\Throwable $e) {
                    return null;
                }
            },
        ];
    }
}
