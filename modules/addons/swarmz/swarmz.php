<?php
/**
 * Swarmz Reseller Console — WHMCS Addon Module
 *
 * The activatable companion to the Swarmz *provisioning* (server) module.
 * Swarmz exposes reselling over its platform API; this console is where the
 * host fine-tunes and translates that for their brand — the host-side knobs we
 * deliberately keep OFF the Swarmz dashboard to keep it tidy — and where the
 * host sees, in one place, which customer is on which plan and the live
 * credit + cloud usage (their wholesale cost) per customer.
 *
 * Lives under modules/addons/swarmz/ and is activated via
 * Setup → Addon Modules. It reuses the provisioning module's tested API client
 * (modules/servers/swarmz/lib) so there is a single source of truth for the
 * HTTP contract.
 *
 * All functions are prefixed `swarmz_` per WHMCS convention. None of these
 * names collide with the server module's hooks (which are CreateAccount,
 * SuspendAccount, … — never config/activate/output), so the two modules can
 * share the `swarmz` name safely.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 * @link https://swarmz.net
 */

use WHMCS\Module\Addon\Swarmz\Console;
use WHMCS\Module\Addon\Swarmz\CreditPacks;
use WHMCS\Module\Addon\Swarmz\PromptBox;

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/lib/Console.php';
require_once __DIR__ . '/lib/CreditPacks.php';
require_once __DIR__ . '/lib/PromptBox.php';
require_once __DIR__ . '/lib/Updater.php';

/**
 * Addon configuration + the host-side settings page.
 *
 * Field array keys double as the storage key (tbladdonmodules.setting) and the
 * key under which the saved value is exposed in $vars — so they are kept equal
 * to their FriendlyName, matching the WHMCS sample convention.
 *
 * @return array
 */
function swarmz_config()
{
    return [
        'name'        => 'Swarmz Reseller Console',
        'description' => 'Resell Swarmz AI workspaces from WHMCS: see which customer is on which plan and their live credit + cloud usage (your wholesale cost), and configure the host-side presentation that is kept off the Swarmz dashboard. Pairs with the Swarmz provisioning (server) module.',
        'author'      => 'Swarmz Labs',
        'language'    => 'english',
        'version'     => '1.18.1',
        'fields'      => [
            'API Base URL' => [
                'FriendlyName' => 'API Base URL',
                'Type'         => 'text',
                'Size'         => '40',
                'Default'      => 'https://api.swarmz.net',
                'Description'  => 'Swarmz platform API base. Leave as default unless Swarmz gave you a private endpoint.',
            ],
            'API Key' => [
                'FriendlyName' => 'API Key',
                'Type'         => 'password',
                'Size'         => '50',
                'Description'  => 'Your <code>sk_live_…</code> platform key. Set it here once — the provisioning (server) module automatically reuses it whenever its own Password field is left blank.',
            ],
            'Editor Button Label' => [
                'FriendlyName' => 'Editor Button Label',
                'Type'         => 'text',
                'Size'         => '30',
                'Default'      => 'Open AI Editor',
                'Description'  => 'Label on the SSO button shown to your customers (e.g. "Open Studio", "Launch Builder").',
            ],
            'Credit Term' => [
                'FriendlyName' => 'Credit Term',
                'Type'         => 'text',
                'Size'         => '20',
                'Default'      => 'credits',
                'Description'  => 'What to call credits in the client area (e.g. "AI actions", "tokens"). Purely cosmetic.',
            ],
            'Support URL' => [
                'FriendlyName' => 'Support URL',
                'Type'         => 'text',
                'Size'         => '40',
                'Description'  => 'Optional. A support/help link shown to customers in the client-area panel.',
            ],
            'Meter Cron Secret' => [
                'FriendlyName' => 'Meter Cron Secret',
                'Type'         => 'password',
                'Size'         => '50',
                'Description'  => 'Optional &amp; advanced. If Swarmz gave you a meter-cron secret, the daily WHMCS cron will also nudge Swarmz\'s usage-metering job with it. Leave blank unless instructed — Swarmz normally runs this job itself, and it is NOT authenticated by your API key.',
            ],
        ],
    ];
}

/**
 * Activation. Creates the prompt-box intent table (mod_swarmz_prompt_intents);
 * everything else reads live from WHMCS + the Swarmz API on each page load.
 *
 * @return array{status:string, description:string}
 */
function swarmz_activate()
{
    PromptBox::ensureSchema();
    CreditPacks::ensureSchema();
    return [
        'status'      => 'success',
        'description' => 'Swarmz Reseller Console activated. Set your API Key in this module\'s settings (if not already), then open it from the sidebar to view customer plans and live usage — and grab your embeddable Prompt Box from the toolbar.',
    ];
}

/**
 * Version upgrade. Ensures the prompt-box schema exists for installs that
 * activated on an older version (activate() only runs once).
 *
 * @param array $vars
 */
function swarmz_upgrade($vars)
{
    PromptBox::ensureSchema();
    CreditPacks::ensureSchema();
}

/**
 * Deactivation. Nothing to tear down (no tables created).
 *
 * @return array{status:string, description:string}
 */
function swarmz_deactivate()
{
    return [
        'status'      => 'success',
        'description' => 'Swarmz Reseller Console deactivated. No customer data or settings were removed.',
    ];
}

/**
 * Admin area output — the reseller dashboard.
 *
 * @param array $vars
 * @return void  (echoes HTML, per WHMCS convention)
 */
function swarmz_output($vars)
{
    $console = new Console($vars);
    echo $console->render();
}
