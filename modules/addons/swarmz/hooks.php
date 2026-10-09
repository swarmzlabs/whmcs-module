<?php
/**
 * Swarmz Reseller Console — addon hooks.
 *
 * Prompt-box plumbing: carry a visitor's prompt intent (the opaque `swzp`
 * token minted by promptbox.php) through the WHMCS cart into the provisioned
 * service, so CreateAccount can attach it to platform-create as
 * `initial_prompt`.
 *
 * The chain has TWO independent binders so ordering quirks between checkout
 * hooks and instant (free-product) provisioning can't drop the prompt:
 *
 *   1. ClientAreaPage       — ?swzp=… seen anywhere in the client area
 *                             (the cart lands here) → PHP session.
 *   2. AfterShoppingCartCheckout — order created → bind the session token to
 *                             the order's swarmz service(s).
 *   3. PreModuleCreate      — belt-and-braces: if provisioning fires in the
 *                             same request BEFORE (2) ran, bind from the
 *                             session right before CreateAccount executes.
 *
 * v1.26.0 adds two routing steps on ClientAreaPage, both GET-only and
 * one-shot per session (see _swarmz_addon_routePrompt):
 *
 *   4. Existing customers keep their prompt — a signed-in client with an
 *      unused session token is sent into a workspace they own (or set up on
 *      the host's Starter Product) by lib/ExistingCustomer.php. Never inside
 *      the cart, never on the chooser page itself, at most 3 tries per token.
 *   5. Auto-open after classic checkout — on cart.php?a=complete, when the
 *      order just provisioned an Active workspace, open the editor at once.
 *
 * Plus daily retention cleanup. Everything is best-effort: a prompt-box
 * failure must never break the cart, checkout, or provisioning.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/lib/PromptBox.php';

use WHMCS\Module\Addon\Swarmz\ExistingCustomer;
use WHMCS\Module\Addon\Swarmz\PromptBox;

/**
 * Capture ?swzp=… into the visitor's session. Fires on every client-area page
 * (including cart.php), so the token survives login/registration during
 * checkout regardless of how many pages the visitor bounces through.
 * Then (v1.26.0) route a signed-in customer's pending prompt — see
 * _swarmz_addon_routePrompt().
 */
add_hook('ClientAreaPage', 1, function ($vars) {
    try {
        $token = isset($_REQUEST[PromptBox::CART_PARAM]) ? (string) $_REQUEST[PromptBox::CART_PARAM] : '';
        if ($token !== '' && preg_match('/^[a-f0-9]{32}$/', $token)) {
            $_SESSION[PromptBox::SESSION_KEY] = $token;
        }
    } catch (\Throwable $e) {
        // never break a page render
    }

    try {
        _swarmz_addon_routePrompt(is_array($vars) ? $vars : []);
    } catch (\Throwable $e) {
        // never break a page render
    }
});

/**
 * Order placed → bind the session's intent token to the order's swarmz
 * service(s). ServiceIDs only contains hosting-product services; we bind to
 * each swarmz one (normally exactly one). The session token is cleared after
 * a successful bind so a later unrelated order can't inherit it; the bound
 * service id is kept (v1.26.0) so the order-complete page can open it.
 */
add_hook('AfterShoppingCartCheckout', 1, function ($vars) {
    try {
        $token = isset($_SESSION[PromptBox::SESSION_KEY]) ? (string) $_SESSION[PromptBox::SESSION_KEY] : '';
        if ($token === '') {
            return;
        }
        $orderId = isset($vars['OrderID']) ? (int) $vars['OrderID'] : null;
        $serviceIds = [];
        if (isset($vars['ServiceIDs']) && is_array($vars['ServiceIDs'])) {
            $serviceIds = $vars['ServiceIDs'];
        } elseif (isset($vars['ServiceIDs']) && is_numeric($vars['ServiceIDs'])) {
            $serviceIds = [(int) $vars['ServiceIDs']];
        }
        $bound = 0;
        foreach ($serviceIds as $sid) {
            $sid = (int) $sid;
            if ($sid > 0 && _swarmz_addon_isSwarmzService($sid) && PromptBox::bindToService($token, $sid, $orderId)) {
                $bound = $sid;
                break; // one prompt → one workspace
            }
        }
        if ($bound > 0) {
            unset($_SESSION[PromptBox::SESSION_KEY]);
            $_SESSION[PromptBox::SESSION_SERVICE_KEY] = $bound;
        }
    } catch (\Throwable $e) {
        // never break checkout
    }
});

/**
 * Belt-and-braces binder: runs immediately before any module Create. If the
 * checkout hook hasn't bound the session token yet (instant-activation
 * free products can provision inside the checkout request), bind it now so
 * CreateAccount's pendingPromptForService() lookup finds it.
 */
add_hook('PreModuleCreate', 1, function ($vars) {
    try {
        $token = isset($_SESSION[PromptBox::SESSION_KEY]) ? (string) $_SESSION[PromptBox::SESSION_KEY] : '';
        if ($token === '') {
            return;
        }
        $params = isset($vars['params']) && is_array($vars['params']) ? $vars['params'] : [];
        $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
        if ($serviceId <= 0 && isset($vars['serviceid'])) {
            $serviceId = (int) $vars['serviceid'];
        }
        if ($serviceId > 0 && _swarmz_addon_isSwarmzService($serviceId) && PromptBox::bindToService($token, $serviceId)) {
            unset($_SESSION[PromptBox::SESSION_KEY]);
            $_SESSION[PromptBox::SESSION_SERVICE_KEY] = $serviceId;
        }
    } catch (\Throwable $e) {
        // never break provisioning
    }
});

/** Daily retention cleanup for stored prompt intents + routing diagnostics. */
add_hook('DailyCronJob', 2, function ($vars) {
    try {
        PromptBox::purgeStale();
    } catch (\Throwable $e) {
        // never break the cron
    }
    try {
        require_once __DIR__ . '/lib/ExistingCustomer.php';
        ExistingCustomer::purgeStale();
    } catch (\Throwable $e) {
        // never break the cron
    }
});

/**
 * v1.26.0 — route a signed-in customer's pending prompt (steps 4 + 5 above).
 *
 * Runs only on a plain GET page load by a logged-in client (never on POST,
 * never on an XHR/AJAX request, never once headers are out), and:
 *
 *   cart.php?a=complete  → auto-open the workspace the just-placed order
 *                          provisioned (one shot: the session's bound
 *                          service id is dropped whatever happens).
 *   any other cart page  → nothing: the cart owns the checkout flow.
 *   the chooser page     → nothing: it is the `ask` policy's destination.
 *   everywhere else      → ExistingCustomer::resolve() with the session
 *                          token, at most MAX_ATTEMPTS times per token and
 *                          never again after a redirect/chooser outcome.
 *
 * On a redirect outcome the token is cleared when the prompt was consumed
 * (opened / new workspace) and kept when the destination needs it (cart).
 */
function _swarmz_addon_routePrompt(array $vars): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return;
    }
    if (headers_sent()) {
        return;
    }
    $clientId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
    if ($clientId <= 0) {
        return;
    }

    $token = isset($_SESSION[PromptBox::SESSION_KEY]) ? (string) $_SESSION[PromptBox::SESSION_KEY] : '';
    if ($token !== '' && !preg_match('/^[a-f0-9]{32}$/', $token)) {
        $token = '';
    }
    $boundServiceId = isset($_SESSION[PromptBox::SESSION_SERVICE_KEY]) ? (int) $_SESSION[PromptBox::SESSION_SERVICE_KEY] : 0;
    if ($token === '' && $boundServiceId <= 0) {
        return; // nothing pending — the common case, no further work
    }

    $filename = isset($vars['filename']) ? (string) $vars['filename'] : '';
    $action = isset($_REQUEST['a']) ? (string) $_REQUEST['a'] : '';

    require_once __DIR__ . '/lib/ExistingCustomer.php';
    if (!class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Helpers')) {
        return; // provisioning module absent — nothing to route into
    }

    // ---- 5. Auto-open after classic checkout ----
    if ($filename === 'cart') {
        if ($action !== 'complete' || ($boundServiceId <= 0 && $token === '')) {
            return;
        }
        // One shot: whatever happens below, the complete page never tries
        // again on reload (the customer still has the panel's launch button).
        unset($_SESSION[PromptBox::SESSION_SERVICE_KEY]);
        if (!\WHMCS\Module\Server\Swarmz\Helpers::openEditorAfterCheckout()) {
            return;
        }
        $r = ExistingCustomer::autoOpenAfterCheckout($clientId, $token, $boundServiceId);
        if (($r['action'] ?? '') === 'redirect') {
            if (!empty($r['clear_token'])) {
                unset($_SESSION[PromptBox::SESSION_KEY]);
            }
            ExistingCustomer::redirect((string) $r['url']);
        }
        return;
    }

    // ---- 4. Existing customers keep their prompt ----
    if ($token === '') {
        return;
    }
    if (isset($_REQUEST['m']) && (string) $_REQUEST['m'] === 'swarmz') {
        return; // the chooser page (index.php?m=swarmz) handles the token itself
    }
    $state = isset($_SESSION[ExistingCustomer::SESSION_STATE_KEY]) && is_array($_SESSION[ExistingCustomer::SESSION_STATE_KEY])
        ? $_SESSION[ExistingCustomer::SESSION_STATE_KEY]
        : null;
    if ($state === null || ($state['token'] ?? '') !== $token) {
        $state = ['token' => $token, 'n' => 0, 'done' => false];
    }
    if (!empty($state['done']) || (int) ($state['n'] ?? 0) >= ExistingCustomer::MAX_ATTEMPTS) {
        return;
    }
    $state['n'] = (int) ($state['n'] ?? 0) + 1;
    $_SESSION[ExistingCustomer::SESSION_STATE_KEY] = $state;

    $r = ExistingCustomer::resolve($clientId, $token);
    $act = $r['action'] ?? 'none';
    if ($act === 'redirect' || $act === 'choose') {
        $state['done'] = true;
        $_SESSION[ExistingCustomer::SESSION_STATE_KEY] = $state;
        if (!empty($r['clear_token'])) {
            unset($_SESSION[PromptBox::SESSION_KEY]);
        }
        ExistingCustomer::redirect((string) $r['url']);
    }
    // 'none' → token kept; this attempt counts toward the per-session cap.
}

/** Is this service backed by the swarmz provisioning module? */
function _swarmz_addon_isSwarmzService(int $serviceId): bool
{
    try {
        return \WHMCS\Database\Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('h.id', $serviceId)
            ->where('p.servertype', 'swarmz')
            ->exists();
    } catch (\Throwable $e) {
        return false;
    }
}
