<?php
/**
 * Swarmz WHMCS Module — existing customers keep their prompt (v1.26.0).
 *
 * A visitor who types a prompt into the storefront Prompt Box but already
 * has an account used to lose it: the widget sent them to the plain WHMCS
 * login page and they landed in the client area. Now the widget stores the
 * prompt as an intent (promptbox.php?a=login) and carries its token through
 * the login; once the customer is signed in, the ClientAreaPage hook
 * (hooks.php) hands the token to resolve() below, which routes the prompt
 * into a workspace the customer owns:
 *
 *   1. List the client's usable workspaces — Active swarmz services with a
 *      tenant id (eligibleServices()).
 *   2. Drop the ones that cannot build (zero credits available per
 *      platform-usage) when there are 2–5 candidates; a usage read failure
 *      keeps everything (filterByCapacity()).
 *   3. Pick one by the host's "Existing Customer Workspace" policy — recent
 *      (last launched into the editor, from mod_swarmz_launches), newest,
 *      oldest — or, with `ask`, render a chooser page (orderByPolicy()).
 *   4. Mint platform-sso for it exactly like the client-area launcher does,
 *      plus one extra field: initial_prompt. On a redirect, consume the
 *      intent, record the launch, and the caller sends the browser there.
 *      A suspended/terminated workspace (409/410) tries the next candidate.
 *   5. With NO usable workspace: set the customer up on the host's "Starter
 *      Product" when it is free (AddOrder + AcceptOrder for this client, the
 *      prompt bound before provisioning so CreateAccount parks it on the new
 *      workspace), then SSO into it; a paid/unset starter sends them to the
 *      cart with the product preselected and the prompt riding along.
 *
 * Also owns the post-checkout auto-open (autoOpenAfterCheckout()): when a
 * classic-cart order provisions instantly, the order-complete page opens the
 * editor instead of waiting for a click.
 *
 * Every outside dependency — WHMCS's localAPI(), the platform-sso mint and
 * the platform-usage read — is injected via $ctx, so the whole decision tree
 * runs offline in test/existing-customer-harness.php against the real
 * PromptBox / ExpressSignup / Helpers code. Nothing here ever throws into a
 * page render: resolve() and friends return an outcome array; the hook
 * decides whether to redirect.
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
require_once __DIR__ . '/ExpressSignup.php';

// Same optional cross-module dependency ExpressSignup/Console declare: the
// provisioning (server) module's Api client + settings readers. Absent server
// module → every entry point below answers "none" (nothing to route into).
$swarmzServerLib = __DIR__ . '/../../../servers/swarmz/lib';
if (is_file($swarmzServerLib . '/Api.php')) {
    require_once $swarmzServerLib . '/Exceptions.php';
    require_once $swarmzServerLib . '/Api.php';
    require_once $swarmzServerLib . '/Helpers.php';
}

class ExistingCustomer
{
    /** Diagnostics log for the console's "Recent existing-customer logins" panel. */
    const LOGINS_TABLE = 'mod_swarmz_existing_logins';

    /** Diagnostics rows older than this are purged by the daily cron. */
    const LOGINS_RETENTION_DAYS = 30;

    /**
     * PHP session key holding this session's per-token routing state:
     * ['token' => …, 'n' => attempts so far, 'done' => bool]. The hook stops
     * calling resolve() for a token after MAX_ATTEMPTS failed tries, and after
     * any redirect/chooser outcome — so an abandoned cart or chooser never
     * bounces the customer out of their client area again.
     */
    const SESSION_STATE_KEY = 'swarmz_ec_state';

    /** Failed resolve() attempts tolerated per token per session. */
    const MAX_ATTEMPTS = 3;

    /**
     * The capacity filter (platform-usage per candidate) only runs for this
     * many candidates: with one there is nothing to choose between, and more
     * than five would mean five+ API round-trips inside a page load.
     */
    const USAGE_FILTER_MIN = 2;
    const USAGE_FILTER_MAX = 5;

    /** Client-area path of the `ask` policy's chooser page. */
    const CHOOSER_PATH = 'index.php?m=swarmz&a=choose';

    // ------------------------------------------------------------ schema

    /**
     * Create this feature's tables if missing — the diagnostics log here and
     * the launch-recency table the server module owns. Idempotent, additive,
     * hasTable-guarded; called from the addon's activate/upgrade and lazily
     * before any write. Never drops or alters an existing table.
     */
    public static function ensureSchema(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::LOGINS_TABLE)) {
                $schema->create(self::LOGINS_TABLE, function ($table) {
                    $table->increments('id');
                    $table->dateTime('created_at');
                    $table->unsignedInteger('client_id')->default(0);
                    $table->unsignedInteger('service_id')->nullable();
                    $table->string('outcome', 40)->default('');
                    $table->string('note', 255)->default('');
                });
            }
        } catch (\Throwable $e) {
            // best-effort — a failure surfaces on first use
        }
        if (self::helpersAvailable()) {
            \WHMCS\Module\Server\Swarmz\Helpers::ensureLaunchesSchema();
        }
    }

    // ------------------------------------------------------ entry points

    /**
     * Route a signed-in client's pending prompt (the intent behind $token)
     * into a workspace. Never throws.
     *
     * @param int    $clientId The logged-in WHMCS client (tblclients.id).
     * @param string $token    The intent token captured into the session.
     * @param array  $ctx      Injectable boundaries, all optional:
     *                           'sso'      => function(int $serviceId, string $tenantId, ?string $initialPrompt): array
     *                                           {ok:true, redirect} | {ok:false, status:int, error:string}
     *                           'usage'    => function(int $serviceId, string $tenantId): ?float
     *                                           credits available; null = unknown / read failed
     *                           'localApi' => function(string $action, array $params): array
     * @return array One of:
     *   ['action' => 'redirect', 'url' => …, 'clear_token' => bool, 'outcome' => …, 'service_id' => ?int]
     *   ['action' => 'choose',   'url' => …, 'clear_token' => false, 'candidates' => [...]]
     *   ['action' => 'none',     'reason' => …]   — leave the page alone (token kept for a retry)
     */
    public static function resolve(int $clientId, string $token, array $ctx = []): array
    {
        try {
            return self::execute($clientId, $token, $ctx + self::defaultContext());
        } catch (\Throwable $e) {
            self::log('ExistingCustomer.Fatal', ['clientid' => $clientId], [
                'error'     => $e->getMessage(),
                'thrown_at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return self::none('fatal');
        }
    }

    /**
     * The chooser's "Build it here": open ONE of the client's workspaces,
     * carrying the pending prompt when $token names an unused intent. The
     * service must be one of the client's eligible workspaces. Never throws.
     *
     * @return array Same shape as resolve().
     */
    public static function launchService(int $clientId, int $serviceId, string $token, array $ctx = []): array
    {
        try {
            $ctx = $ctx + self::defaultContext();
            if ($clientId <= 0 || $serviceId <= 0 || !self::helpersAvailable()) {
                return self::none('unavailable');
            }
            $chosen = null;
            foreach (self::eligibleServices($clientId) as $svc) {
                if ((int) $svc['id'] === $serviceId) {
                    $chosen = $svc;
                    break;
                }
            }
            if ($chosen === null) {
                return self::none('not_eligible');
            }
            $prompt = null;
            if ($token !== '') {
                $intent = PromptBox::findIntent($token);
                if ($intent && empty($intent->used_at)) {
                    $prompt = trim((string) $intent->prompt);
                }
            }
            return self::openInto($clientId, [$chosen], $prompt === null || $prompt === '' ? '' : $token, $prompt, $ctx);
        } catch (\Throwable $e) {
            self::log('ExistingCustomer.Fatal', ['clientid' => $clientId, 'serviceid' => $serviceId], [
                'error' => $e->getMessage(), 'thrown_at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return self::none('fatal');
        }
    }

    /**
     * The chooser's "Start a new workspace" (and resolve()'s zero-workspace
     * branch): free Starter Product → order, provision, SSO; otherwise the
     * cart. $token may be '' (no pending prompt). Never throws.
     *
     * @return array Same shape as resolve().
     */
    public static function startNew(int $clientId, string $token, array $ctx = []): array
    {
        try {
            $ctx = $ctx + self::defaultContext();
            if ($clientId <= 0 || !self::helpersAvailable()) {
                return self::none('unavailable');
            }
            $prompt = '';
            $intentPid = 0;
            if ($token !== '') {
                $intent = PromptBox::findIntent($token);
                if ($intent && empty($intent->used_at)) {
                    $prompt = trim((string) $intent->prompt);
                    $intentPid = (int) $intent->pid;
                } else {
                    $token = ''; // unknown or spent — nothing to carry
                }
            }
            return self::startNewWorkspace($clientId, $token, $prompt, $intentPid, $ctx);
        } catch (\Throwable $e) {
            self::log('ExistingCustomer.Fatal', ['clientid' => $clientId], [
                'error' => $e->getMessage(), 'thrown_at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return self::none('fatal');
        }
    }

    /**
     * Post-checkout auto-open: the classic cart just provisioned a workspace
     * for this client (free / instant products) and the order-complete page
     * is rendering. Open the editor now — WITHOUT initial_prompt, since
     * CreateAccount already parked the prompt on the workspace. The service
     * comes from the intent bound during checkout ($token) or the session's
     * bound service id ($sessionServiceId); it must belong to this client,
     * be Active and have a tenant id. Never throws.
     *
     * @return array Same shape as resolve().
     */
    public static function autoOpenAfterCheckout(int $clientId, string $token, int $sessionServiceId, array $ctx = []): array
    {
        try {
            $ctx = $ctx + self::defaultContext();
            if ($clientId <= 0 || !self::helpersAvailable()) {
                return self::none('unavailable');
            }
            $serviceId = 0;
            if ($token !== '') {
                $intent = PromptBox::findIntent($token);
                if ($intent && !empty($intent->service_id)) {
                    $serviceId = (int) $intent->service_id;
                }
            }
            if ($serviceId <= 0) {
                $serviceId = $sessionServiceId;
            }
            if ($serviceId <= 0) {
                return self::none('no_service');
            }
            $chosen = null;
            foreach (self::eligibleServices($clientId) as $svc) {
                if ((int) $svc['id'] === $serviceId) {
                    $chosen = $svc;
                    break;
                }
            }
            if ($chosen === null) {
                return self::none('not_ready'); // still provisioning / pending payment
            }
            $res = $ctx['sso']($serviceId, (string) $chosen['tenant_id'], null);
            if (!is_array($res) || empty($res['ok']) || empty($res['redirect'])) {
                self::recordLogin($clientId, $serviceId, 'checkout_failed', self::ssoFailureNote($res));
                return self::none('sso_failed');
            }
            if ($token !== '') {
                PromptBox::consumeToken($token, $serviceId); // idempotent — CreateAccount usually did this
            }
            \WHMCS\Module\Server\Swarmz\Helpers::recordLaunch($serviceId);
            self::recordLogin($clientId, $serviceId, 'checkout_open');
            return self::redirectOutcome((string) $res['redirect'], true, 'checkout_open', $serviceId);
        } catch (\Throwable $e) {
            self::log('ExistingCustomer.Fatal', ['clientid' => $clientId], [
                'error' => $e->getMessage(), 'thrown_at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return self::none('fatal');
        }
    }

    /**
     * Send the browser to $url (303 — the request that triggered us may be
     * the result of a POST) and stop. Only http(s) URLs are honoured. Outside
     * a live WHMCS request (CLI/test harness) this is a no-op so callers can
     * assert on the outcome array instead.
     */
    public static function redirect(string $url): void
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return;
        }
        if (php_sapi_name() === 'cli' || headers_sent()) {
            return;
        }
        header('Location: ' . $url, true, 303);
        exit;
    }

    // ------------------------------------------------------- the decision

    /** @param array $ctx Already merged with defaultContext(). */
    private static function execute(int $clientId, string $token, array $ctx): array
    {
        if ($clientId <= 0 || $token === '' || !self::helpersAvailable()) {
            return self::none('unavailable');
        }
        $intent = PromptBox::findIntent($token);
        if (!$intent || !empty($intent->used_at)) {
            return self::none('no_intent');
        }
        $prompt = trim((string) $intent->prompt);
        if ($prompt === '') {
            return self::none('no_intent');
        }
        $intentPid = (int) $intent->pid;

        $candidates = self::eligibleServices($clientId);
        if (empty($candidates)) {
            return self::startNewWorkspace($clientId, $token, $prompt, $intentPid, $ctx);
        }

        $policy = \WHMCS\Module\Server\Swarmz\Helpers::existingCustomerPolicy();
        $candidates = self::filterByCapacity($candidates, $ctx);
        $candidates = self::orderByPolicy($candidates, $policy);

        if ($policy === 'ask') {
            self::recordLogin($clientId, null, 'chooser', count($candidates) . ' workspace(s) offered');
            return [
                'action'      => 'choose',
                'url'         => self::chooserUrl(),
                'clear_token' => false,
                'candidates'  => $candidates,
            ];
        }
        return self::openInto($clientId, $candidates, $token, $prompt, $ctx);
    }

    /**
     * Mint SSO into the first candidate that accepts it, carrying the prompt.
     * 409/410 (suspended/terminated on the platform — WHMCS status has
     * drifted) moves on to the next candidate; any other failure stops and
     * leaves the page alone so a later page load can retry (the hook caps
     * retries per session).
     *
     * @param array<int,array> $candidates Ordered, from orderByPolicy().
     * @param string           $token      '' when there is no intent to consume.
     * @param string|null      $prompt     initial_prompt, or null/'' for none.
     */
    private static function openInto(int $clientId, array $candidates, string $token, ?string $prompt, array $ctx): array
    {
        $initialPrompt = ($prompt !== null && $prompt !== '') ? $prompt : null;
        $skipped = [];
        foreach ($candidates as $svc) {
            $serviceId = (int) $svc['id'];
            $res = $ctx['sso']($serviceId, (string) $svc['tenant_id'], $initialPrompt);
            if (is_array($res) && !empty($res['ok']) && !empty($res['redirect'])) {
                if ($token !== '') {
                    PromptBox::consumeToken($token, $serviceId);
                }
                \WHMCS\Module\Server\Swarmz\Helpers::recordLaunch($serviceId);
                $note = $initialPrompt !== null ? 'prompt carried' : 'no prompt';
                if (!empty($skipped)) {
                    $note .= '; skipped #' . implode(', #', $skipped);
                }
                self::recordLogin($clientId, $serviceId, 'opened', $note);
                return self::redirectOutcome((string) $res['redirect'], $token !== '', 'opened', $serviceId);
            }
            $status = is_array($res) ? (int) ($res['status'] ?? 0) : 0;
            if ($status === 409 || $status === 410) {
                self::log('ExistingCustomer.SsoSkipped', ['serviceid' => $serviceId], [
                    'status' => $status, 'error' => is_array($res) ? ($res['error'] ?? '') : '',
                ]);
                $skipped[] = $serviceId;
                continue;
            }
            self::recordLogin($clientId, $serviceId, 'failed', self::ssoFailureNote($res));
            return self::none('sso_failed');
        }
        self::recordLogin($clientId, null, 'failed', 'every workspace refused SSO (suspended/terminated): #' . implode(', #', $skipped));
        return self::none('all_refused');
    }

    /**
     * No usable workspace: free Starter Product → place + accept the order
     * for THIS client with the prompt bound up front (so CreateAccount parks
     * it as initial_prompt), then SSO into the new workspace. Paid or unset
     * starter → the cart with the product preselected and the token along.
     */
    private static function startNewWorkspace(int $clientId, string $token, string $prompt, int $intentPid, array $ctx): array
    {
        $starterPid = \WHMCS\Module\Server\Swarmz\Helpers::starterProductId();
        if ($starterPid > 0 && !PromptBox::isSwarmzProduct($starterPid)) {
            self::log('ExistingCustomer.StarterInvalid', ['pid' => $starterPid], ['note' => 'Starter Product is not a swarmz product — sending to cart']);
            $starterPid = 0;
        }
        $cartPid = $starterPid > 0 ? $starterPid : $intentPid;
        if ($cartPid <= 0) {
            return self::none('no_product');
        }
        $cartUrl = PromptBox::cartUrl($cartPid, $token);

        if ($starterPid <= 0 || !ExpressSignup::isFreeProduct($starterPid, $clientId)) {
            self::recordLogin($clientId, null, 'cart', 'product #' . $cartPid . ($starterPid > 0 ? ' (paid starter)' : ' (no starter set)'));
            return self::redirectOutcome($cartUrl, false, 'cart', null);
        }

        // Free starter: the express sequence, for an existing client. Give the
        // AddOrder → AcceptOrder → provision → SSO chain room to finish.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        $paymentMethod = ExpressSignup::resolvePaymentMethod();
        if ($paymentMethod === '') {
            self::log('ExistingCustomer.NoGateway', ['clientid' => $clientId], ['note' => 'no active payment gateway configured — sending to cart']);
            self::recordLogin($clientId, null, 'cart', 'no payment gateway for the starter order');
            return self::redirectOutcome($cartUrl, false, 'cart', null);
        }
        $cycle = ExpressSignup::resolveBillingCycle($starterPid);
        $orderParams = [
            'clientid'       => $clientId,
            'paymentmethod'  => $paymentMethod,
            'pid'            => [$starterPid],
            'billingcycle'   => [$cycle],
            'noinvoiceemail' => true,
            'noemail'        => true,
        ];
        $orderResp = $ctx['localApi']('AddOrder', $orderParams);
        $orderResp = is_array($orderResp) ? $orderResp : [];
        self::log('ExistingCustomer.AddOrder', $orderParams, $orderResp);
        $orderId = (($orderResp['result'] ?? '') === 'success') ? (int) ($orderResp['orderid'] ?? 0) : 0;
        if ($orderId <= 0) {
            // A third-party hook may have thrown AFTER the order rows were
            // written (see ExpressSignup). Recover ONLY an order that is
            // provably ours: this client, this product, placed just now.
            $orderId = self::recoverStarterOrder($clientId, $starterPid);
            if ($orderId > 0) {
                self::log('ExistingCustomer.AddOrderRecovered', ['clientid' => $clientId], ['orderid' => $orderId]);
            } else {
                self::recordLogin($clientId, null, 'cart', 'starter AddOrder failed');
                return self::redirectOutcome($cartUrl, false, 'cart', null);
            }
        }

        $serviceId = ExpressSignup::resolveServiceId($orderResp, $orderId, $clientId, $starterPid);
        if ($token !== '' && $serviceId > 0) {
            // Bind BEFORE provisioning so CreateAccount's pendingPromptForService()
            // read finds it (the PreModuleCreate binder is the belt-and-braces).
            PromptBox::bindToService($token, $serviceId, $orderId);
        }

        $acceptParams = ['orderid' => $orderId, 'autosetup' => true, 'sendemail' => true];
        $acceptResp = $ctx['localApi']('AcceptOrder', $acceptParams);
        self::log('ExistingCustomer.AcceptOrder', $acceptParams, is_array($acceptResp) ? $acceptResp : []);

        if ($serviceId <= 0) {
            $serviceId = ExpressSignup::resolveServiceId($orderResp, $orderId, $clientId, $starterPid);
        }
        if ($serviceId <= 0) {
            self::recordLogin($clientId, null, 'new_workspace', 'order #' . $orderId . ' accepted; service id unresolved');
            return self::redirectOutcome(self::clientAreaUrl('clientarea.php?action=services'), true, 'new_workspace', null);
        }

        $tenantId = \WHMCS\Module\Server\Swarmz\Helpers::getTenantId($serviceId);
        if ($tenantId !== null && $tenantId !== '') {
            // CreateAccount normally consumed the prompt (initial_prompt on
            // platform-create). If it could not — bind raced, prompt still
            // unused — carry it on the SSO instead so it is never lost.
            $carry = null;
            if ($token !== '') {
                $after = PromptBox::findIntent($token);
                if ($after && empty($after->used_at) && $prompt !== '') {
                    $carry = $prompt;
                }
            }
            $res = $ctx['sso']($serviceId, $tenantId, $carry);
            if (is_array($res) && !empty($res['ok']) && !empty($res['redirect'])) {
                if ($token !== '') {
                    PromptBox::consumeToken($token, $serviceId);
                }
                \WHMCS\Module\Server\Swarmz\Helpers::recordLaunch($serviceId);
                self::recordLogin($clientId, $serviceId, 'new_workspace', 'starter #' . $starterPid . ', order #' . $orderId);
                return self::redirectOutcome((string) $res['redirect'], true, 'new_workspace', $serviceId);
            }
            self::recordLogin($clientId, $serviceId, 'new_workspace', 'provisioned; SSO failed — ' . self::ssoFailureNote($res));
        } else {
            self::recordLogin($clientId, $serviceId, 'new_workspace', 'order #' . $orderId . ' accepted; no tenant id yet');
        }
        // The workspace exists (or is still provisioning): land on its page;
        // the prompt stays bound to it for CreateAccount / a later open.
        return self::redirectOutcome(self::clientAreaUrl('clientarea.php?action=productdetails&id=' . $serviceId), true, 'new_workspace', $serviceId);
    }

    // ------------------------------------------------------- candidates

    /**
     * The client's usable workspaces: Active services on a swarmz-module
     * product that have a tenant id. Suspended / Terminated / Cancelled /
     * Pending services and anything not yet provisioned are excluded.
     *
     * @return array<int,array{id:int,pid:int,product:string,domain:string,regdate:string,tenant_id:string}>
     */
    public static function eligibleServices(int $clientId): array
    {
        if ($clientId <= 0 || !self::helpersAvailable()) {
            return [];
        }
        try {
            $products = [];
            foreach (Capsule::table('tblproducts')->where('servertype', 'swarmz')->get(['id', 'name']) as $p) {
                $products[(int) $p->id] = (string) $p->name;
            }
            if (empty($products)) {
                return [];
            }
            $rows = Capsule::table('tblhosting')
                ->where('userid', $clientId)
                ->where('domainstatus', 'Active')
                ->get(['id', 'packageid', 'domain', 'regdate']);
            $out = [];
            foreach ($rows as $r) {
                $pid = (int) $r->packageid;
                if (!isset($products[$pid])) {
                    continue;
                }
                $tenantId = \WHMCS\Module\Server\Swarmz\Helpers::getTenantId((int) $r->id);
                if ($tenantId === null || $tenantId === '') {
                    continue;
                }
                $out[] = [
                    'id'        => (int) $r->id,
                    'pid'       => $pid,
                    'product'   => $products[$pid],
                    'domain'    => (string) ($r->domain ?? ''),
                    'regdate'   => (string) ($r->regdate ?? ''),
                    'tenant_id' => $tenantId,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Drop candidates whose workspace has no credits left to build with —
     * bounded to 2–5 candidates (one platform-usage read each). A failed
     * read (null) keeps that candidate. If the filter would drop EVERY
     * candidate, the unfiltered list is kept: the customer still owns those
     * workspaces and the editor itself explains the credit situation,
     * whereas routing them to a brand-new product would be a surprise.
     *
     * @param array<int,array> $candidates
     * @return array<int,array>
     */
    public static function filterByCapacity(array $candidates, array $ctx): array
    {
        $n = count($candidates);
        if ($n < self::USAGE_FILTER_MIN || $n > self::USAGE_FILTER_MAX || !isset($ctx['usage'])) {
            return $candidates;
        }
        $kept = [];
        foreach ($candidates as $svc) {
            $avail = null;
            try {
                $avail = $ctx['usage']((int) $svc['id'], (string) $svc['tenant_id']);
            } catch (\Throwable $e) {
                $avail = null;
            }
            if ($avail !== null && is_numeric($avail) && (float) $avail <= 0.0) {
                self::log('ExistingCustomer.NoCapacity', ['serviceid' => (int) $svc['id']], ['credits_available' => (float) $avail]);
                continue;
            }
            $kept[] = $svc;
        }
        return empty($kept) ? $candidates : $kept;
    }

    /**
     * Order candidates by policy (first = chosen):
     *   recent  launched ones first, latest launch first; never-launched
     *           after them, newest regdate first.
     *   newest  regdate desc, then id desc.
     *   oldest  regdate asc, then id asc.
     *   ask     recent ordering (how the chooser lists them).
     *
     * @param array<int,array> $candidates
     * @return array<int,array>
     */
    public static function orderByPolicy(array $candidates, string $policy): array
    {
        $byNewest = static function (array $a, array $b): int {
            $cmp = strcmp((string) $b['regdate'], (string) $a['regdate']);
            return $cmp !== 0 ? $cmp : ((int) $b['id'] <=> (int) $a['id']);
        };
        if ($policy === 'newest') {
            usort($candidates, $byNewest);
            return $candidates;
        }
        if ($policy === 'oldest') {
            usort($candidates, static function (array $a, array $b): int {
                $cmp = strcmp((string) $a['regdate'], (string) $b['regdate']);
                return $cmp !== 0 ? $cmp : ((int) $a['id'] <=> (int) $b['id']);
            });
            return $candidates;
        }
        // recent (and ask)
        $launches = \WHMCS\Module\Server\Swarmz\Helpers::lastLaunchMap(array_map(static function (array $s): int {
            return (int) $s['id'];
        }, $candidates));
        usort($candidates, static function (array $a, array $b) use ($launches, $byNewest): int {
            $la = $launches[(int) $a['id']] ?? '';
            $lb = $launches[(int) $b['id']] ?? '';
            if ($la !== '' && $lb !== '') {
                $cmp = strcmp($lb, $la); // latest launch first
                return $cmp !== 0 ? $cmp : $byNewest($a, $b);
            }
            if ($la !== '') {
                return -1;
            }
            if ($lb !== '') {
                return 1;
            }
            return $byNewest($a, $b);
        });
        return $candidates;
    }

    /**
     * Credits a workspace can still spend, from a platform-usage response:
     * the sum of every remaining/available pool on balances.by_workspace[0]
     * (included, rollover, top-up, AI, cloud, free). An unlimited free lane
     * (free_remaining === null) counts as capacity. Null when the response
     * carries no balances at all (older platform) — the caller keeps the
     * candidate.
     */
    public static function creditsAvailableFromUsage(array $body): ?float
    {
        $ws = $body['balances']['by_workspace'][0] ?? null;
        if (!is_array($ws)) {
            return null;
        }
        $known = false;
        $sum = 0.0;
        foreach (['included_remaining', 'rollover_remaining', 'topup_available', 'ai_grant_remaining', 'cloud_grant_remaining'] as $k) {
            if (isset($ws[$k]) && is_numeric($ws[$k])) {
                $sum += (float) $ws[$k];
                $known = true;
            }
        }
        if (array_key_exists('free_remaining', $ws)) {
            if ($ws['free_remaining'] === null) {
                return PHP_FLOAT_MAX; // unlimited free lane
            }
            if (is_numeric($ws['free_remaining'])) {
                $sum += (float) $ws['free_remaining'];
                $known = true;
            }
        }
        return $known ? $sum : null;
    }

    // ------------------------------------------------------ diagnostics

    /** One row for the console's "Recent existing-customer logins" panel. Never throws. */
    public static function recordLogin(int $clientId, ?int $serviceId, string $outcome, string $note = ''): void
    {
        try {
            self::ensureSchema();
            Capsule::table(self::LOGINS_TABLE)->insert([
                'created_at' => date('Y-m-d H:i:s'),
                'client_id'  => $clientId,
                'service_id' => $serviceId !== null && $serviceId > 0 ? $serviceId : null,
                'outcome'    => substr($outcome, 0, 40),
                'note'       => substr($note, 0, 255),
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Newest-first diagnostics rows (id, created_at, client_id, service_id,
     * outcome, note), capped at 100 regardless of what the caller asks for.
     *
     * @return array<int,\stdClass>
     */
    public static function recentLogins(int $limit = 20): array
    {
        try {
            if (!Capsule::schema()->hasTable(self::LOGINS_TABLE)) {
                return [];
            }
            $rows = Capsule::table(self::LOGINS_TABLE)
                ->orderBy('id', 'desc')
                ->limit(max(1, min(100, $limit)))
                ->get(['id', 'created_at', 'client_id', 'service_id', 'outcome', 'note']);
            $out = [];
            foreach ($rows as $r) {
                $out[] = $r;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Purge diagnostics rows past retention (daily cron). Module-owned table only. */
    public static function purgeStale(): int
    {
        try {
            if (!Capsule::schema()->hasTable(self::LOGINS_TABLE)) {
                return 0;
            }
            $cutoff = date('Y-m-d H:i:s', time() - self::LOGINS_RETENTION_DAYS * 86400);
            return (int) Capsule::table(self::LOGINS_TABLE)->where('created_at', '<', $cutoff)->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ---------------------------------------------------------- plumbing

    /** Absolute URL of the `ask` chooser page. */
    public static function chooserUrl(): string
    {
        return self::clientAreaUrl(self::CHOOSER_PATH);
    }

    private static function clientAreaUrl(string $path): string
    {
        return rtrim(PromptBox::systemUrl(), '/') . '/' . ltrim($path, '/');
    }

    private static function helpersAvailable(): bool
    {
        return class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Helpers')
            && class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Api');
    }

    private static function none(string $reason): array
    {
        return ['action' => 'none', 'reason' => $reason];
    }

    private static function redirectOutcome(string $url, bool $clearToken, string $outcome, ?int $serviceId): array
    {
        return [
            'action'      => 'redirect',
            'url'         => $url,
            'clear_token' => $clearToken,
            'outcome'     => $outcome,
            'service_id'  => $serviceId,
        ];
    }

    private static function ssoFailureNote($res): string
    {
        if (!is_array($res)) {
            return 'sso: no response';
        }
        return 'sso: HTTP ' . (int) ($res['status'] ?? 0) . ' ' . (string) ($res['error'] ?? '');
    }

    /**
     * The just-placed starter order for THIS client + product, if AddOrder
     * died after writing it (third-party hooks run inside AddOrder). Unlike
     * ExpressSignup's recovery — safe there because the client was created
     * seconds earlier — an existing client may have unrelated recent orders,
     * so only a Pending order from the last three minutes whose service row
     * is on the starter product qualifies.
     */
    private static function recoverStarterOrder(int $clientId, int $pid): int
    {
        try {
            $since = date('Y-m-d H:i:s', time() - 180);
            $svc = Capsule::table('tblhosting')
                ->where('userid', $clientId)
                ->where('packageid', $pid)
                ->orderBy('id', 'desc')
                ->first(['orderid']);
            $orderId = $svc ? (int) ($svc->orderid ?? 0) : 0;
            if ($orderId <= 0) {
                return 0;
            }
            $order = Capsule::table('tblorders')
                ->where('id', $orderId)
                ->where('userid', $clientId)
                ->where('status', 'Pending')
                ->where('date', '>=', $since)
                ->first(['id']);
            return $order ? (int) $order->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Module-log wrapper (key-redacting, never throws). */
    private static function log(string $action, array $request, array $response): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        try {
            logModuleCall('swarmz', $action, $request, $response, $response, ['sk_live_', 'sk_test_', 'Bearer ']);
        } catch (\Throwable $e) {
            // Logging must never break a page.
        }
    }

    /**
     * Production implementations of the injectable boundaries. The SSO mint
     * is the client-area launcher's call (_swarmz_doSso) — external_ref +
     * tenant_id, Api::withPortal, per-service server key then addon key —
     * with the one extra field initial_prompt (platform-side feature shipped
     * in parallel; older platforms ignore unknown fields).
     */
    private static function defaultContext(): array
    {
        return [
            'localApi' => ExpressSignup::localApiBoundary(),
            'sso' => static function (int $serviceId, string $tenantId, ?string $initialPrompt): array {
                $api = null;
                try {
                    $api = \WHMCS\Module\Server\Swarmz\Helpers::makeApiClientForService($serviceId);
                    $body = [
                        'external_ref' => \WHMCS\Module\Server\Swarmz\Helpers::buildExternalRef($serviceId),
                        'tenant_id'    => $tenantId,
                    ];
                    if ($initialPrompt !== null && $initialPrompt !== '') {
                        $body['initial_prompt'] = $initialPrompt;
                    }
                    $result = $api->postPlatform('platform-sso', \WHMCS\Module\Server\Swarmz\Api::withPortal($body));
                    $resp = isset($result['body']) && is_array($result['body']) ? $result['body'] : [];
                    self::logApi('ExistingCustomer.SSO', $body, $resp, $api->maskedKey());
                    $redirect = $resp['redirectTo'] ?? null;
                    if (is_string($redirect) && $redirect !== '') {
                        return ['ok' => true, 'redirect' => $redirect];
                    }
                    return ['ok' => false, 'status' => 0, 'error' => 'no_redirect'];
                } catch (\WHMCS\Module\Server\Swarmz\SwarmzApiException $e) {
                    self::logApi('ExistingCustomer.SSO.Error', ['serviceid' => $serviceId], [
                        'error' => $e->getMessage(), 'status' => $e->getStatusCode(),
                    ], $api ? $api->maskedKey() : '');
                    return ['ok' => false, 'status' => $e->getStatusCode(), 'error' => $e->getErrorCode()];
                } catch (\Throwable $e) {
                    self::logApi('ExistingCustomer.SSO.Error', ['serviceid' => $serviceId], [
                        'error' => $e->getMessage(),
                    ], $api ? $api->maskedKey() : '');
                    return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
                }
            },
            'usage' => static function (int $serviceId, string $tenantId): ?float {
                $api = null;
                try {
                    $api = \WHMCS\Module\Server\Swarmz\Helpers::makeApiClientForService($serviceId);
                    // Same read swarmz_UsageUpdate makes: tenant_id resolves the
                    // workspace, external_ref is log correlation only.
                    $body = [
                        'tenant_id'    => $tenantId,
                        'external_ref' => \WHMCS\Module\Server\Swarmz\Helpers::buildExternalRef($serviceId),
                        'period'       => 'current_month',
                    ];
                    $result = $api->postPlatform('platform-usage', $body);
                    $resp = isset($result['body']) && is_array($result['body']) ? $result['body'] : [];
                    return self::creditsAvailableFromUsage($resp);
                } catch (\Throwable $e) {
                    self::logApi('ExistingCustomer.Usage.Error', ['serviceid' => $serviceId], [
                        'error' => $e->getMessage(),
                    ], $api ? $api->maskedKey() : '');
                    return null;
                }
            },
        ];
    }

    /** Module-log wrapper for platform calls — redacts the masked key too. */
    private static function logApi(string $action, array $request, array $response, string $maskedKey): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        $replace = ['sk_live_', 'sk_test_', 'Bearer '];
        if ($maskedKey !== '') {
            $replace[] = $maskedKey;
        }
        try {
            logModuleCall('swarmz', $action, $request, $response, $response, $replace);
        } catch (\Throwable $e) {
            // never break a page over logging
        }
    }
}
