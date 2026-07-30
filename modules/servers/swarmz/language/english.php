<?php
/**
 * Swarmz WHMCS Module — client-area strings (English, the fallback base).
 *
 * CONTRACT (v1.13.0): each language file RETURNS a flat key => string array.
 * Helpers::clientLang() loads english.php first and overlays the client's
 * language file, so a missing key can never blank the UI — it just shows
 * English. Sibling files: german.php, french.php, italian.php, spanish.php.
 *
 * RULE (see AGENTS.md): every new user-facing string added to any template
 * ships in ALL language files in the same change — never English-only.
 *
 * %s placeholders are substituted in the template via |replace.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

return [
    // Hero
    'workspace_title'     => 'Your workspace',
    'workspace_ready'     => 'Ready — jump back into the editor any time.',
    'workspace_preparing' => 'Being prepared…',
    'provisioning_notice' => 'Your workspace is being provisioned. If this message persists for more than a few minutes, please contact support.',

    // Section titles ("Your" is combined with the host's credit term)
    'section_your' => 'Your %s',
    'plan_section' => 'Plan',

    // Card labels (combined with the host's credit term where noted)
    'free_label' => 'Free %s',
    'monthly_label' => 'Monthly %s',
    'cloud_label' => 'Cloud %s',
    'ai_label' => 'AI %s',
    'extra_label' => 'Extra %s',

    // Cadence chips
    'cadence_daily'    => 'Daily',
    'cadence_monthly'  => 'Monthly',
    'cadence_one_time' => 'One-time',
    'cadence_cycle'    => 'Per cycle',
    'cadence_topup'    => 'Top-up',

    // Card copy
    'not_included'    => 'Not included on this plan',
    'unlimited'       => 'Unlimited',
    'one_time_note'   => 'One-time allowance — does not renew',
    'monthly_note'    => 'Replenishes monthly',
    'per_day'         => 'per day',
    'resets_midnight' => 'resets at 00:00 UTC',
    'up_to_month'     => 'Up to %s per month',
    'renews_cycle'    => 'Renews with your billing cycle',
    'rolled_over'     => '+ %s carried over',
    'carry_over'      => 'Unused amounts carry over for %s mo.',
    'topup_note'      => 'Purchased top-ups — valid for 12 months',
    'topup_used'      => '%s used so far',

    // Plan cards
    'published_apps'  => 'Published apps',
    'live_now'        => 'Live right now',
    'allowed_at_once' => 'Allowed at once',
    'custom_domains'  => 'Custom domains',
    'not_available'   => 'Not available on this plan',
    'connected'       => 'Connected',
    'allowed'         => 'Allowed',

    // Buy row + packs modal
    'buy_prompt'   => 'Running low? Buy a top-up any time — it lands in your workspace as soon as payment clears.',
    'buy_button'   => 'Buy more',
    'modal_title'  => 'Top-up packs',
    'modal_sub'    => 'Pick a pack — it is added to your workspace automatically after payment.',
    'order_now'    => 'Order',
    'price_free'   => 'Free',
    'chip_onetime' => 'One-time',
    'chip_monthly' => 'Monthly',
    'no_packs'     => 'No packs are available right now.',
    'close'        => 'Close',

    // Footer + errors
    'usage_error'     => 'Couldn’t refresh usage just now:',
    'need_help'       => 'Need help?',
    'contact_support' => 'Contact support',
];
