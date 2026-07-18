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

use WHMCS\Module\Addon\Swarmz\PromptBox;

/**
 * Capture ?swzp=… into the visitor's session. Fires on every client-area page
 * (including cart.php), so the token survives login/registration during
 * checkout regardless of how many pages the visitor bounces through.
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
});

/**
 * Order placed → bind the session's intent token to the order's swarmz
 * service(s). ServiceIDs only contains hosting-product services; we bind to
 * each swarmz one (normally exactly one). The session token is cleared after
 * a successful bind so a later unrelated order can't inherit it.
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
        $bound = false;
        foreach ($serviceIds as $sid) {
            $sid = (int) $sid;
            if ($sid > 0 && _swarmz_addon_isSwarmzService($sid) && PromptBox::bindToService($token, $sid, $orderId)) {
                $bound = true;
                break; // one prompt → one workspace
            }
        }
        if ($bound) {
            unset($_SESSION[PromptBox::SESSION_KEY]);
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
        }
    } catch (\Throwable $e) {
        // never break provisioning
    }
});

/** Daily retention cleanup for stored prompt intents. */
add_hook('DailyCronJob', 2, function ($vars) {
    try {
        PromptBox::purgeStale();
    } catch (\Throwable $e) {
        // never break the cron
    }
});

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
