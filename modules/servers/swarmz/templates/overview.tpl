{*
 * Client-area overview template.
 *
 * Deliberately UNBRANDED: no "swarmz" marks here — the host resells this under
 * their own brand. The SSO button label, the word used for "credits", and which
 * spend figures show are all host-configurable via the Reseller Console addon.
 *
 * Credits are THREE separate pools — never one merged number:
 *   1. Free credits    — daily allowance (resets 00:00 UTC), optional monthly cap.
 *   2. Monthly credits — paid grant, resets on the billing cycle, may roll over.
 *   3. Top-up credits  — one-off / purchased.
 *
 * Values + plan caps come entirely from the platform-usage API response (live
 * per-pool balances + balances.by_workspace[].caps); the module no longer holds
 * any locally-configured allowances.
 *
 * Provided variables (set by swarmz_ClientArea):
 *   tenantId             string|null
 *   dashboardUrl         string|null
 *   ssoUrl               string  — WHMCS SSO trigger URL
 *   usage                array   — from swarmz_UsageUpdate
 *
 *   --- Credit pools ---
 *   freeDaily            int|null   — daily free allowance (null = unlimited)
 *   freeDailyUsed        int|null   — used today (often null; not tracked offline)
 *   freeMonthlyCap       int|null   — optional monthly free ceiling (null = none)
 *   monthlyCredits       int|float  — paid monthly grant size (0 = none)
 *   monthlyUsed          int|float|null
 *   monthlyRemaining     int|float|null
 *   rolloverCredits      int|float|null — carried-over remaining (null = n/a)
 *   rolloverMonths       int        — configured carry-over months (0 = none)
 *   topupCredits         int|float  — top-up pool size (0 = none)
 *   topupUsed            int|float|null
 *   topupRemaining       int|float|null
 *   creditsSource        string     — 'live' | 'config'
 *
 *   --- Other usage ---
 *   creditsUsed          float
 *   cloudUsd             float
 *   usdCredits           float
 *   projectsCount        int|null
 *   domainsCount         int|null   — live count (often null; API doesn't return it)
 *   domainsLimit         int|null   — null = unlimited
 *   publishedCount       int|null
 *   publishedLimit       int|null   — null = unlimited
 *   customDomainsEnabled bool
 *
 *   --- Host-configurable via the Reseller Console addon module ---
 *   editorButtonLabel  string  — SSO button text
 *   creditTerm         string  — what to call "credits"
 *   showAiSpend        bool    — show AI USD spend card
 *   showCloudSpend     bool    — show cloud USD spend card
 *   supportUrl         string  — optional host support link
 *}

{assign var="ct" value=$creditTerm|default:'credits'}

{* Resolve "remaining" for each pool. Prefer an explicit *Remaining; otherwise
   fall back to the pool size (a fresh, unused allowance).
   NOTE: config-option values arrive as STRINGS, so we gate the subtraction on
   is_numeric for BOTH operands — PHP 8 throws a fatal "Unsupported operand
   types: float - string" on arithmetic with a non-numeric string, and a bare
   `!== null` check does not catch an empty/non-numeric string. *}
{assign var="freeRemaining" value=$freeDaily}
{if $freeDaily !== null && $freeDailyUsed !== null && $freeDaily|is_numeric && $freeDailyUsed|is_numeric}
    {assign var="freeRemaining" value=$freeDaily-$freeDailyUsed}
    {if $freeRemaining < 0}{assign var="freeRemaining" value=0}{/if}
{/if}

{if $monthlyRemaining !== null}
    {assign var="monthlyRem" value=$monthlyRemaining}
{else}
    {assign var="monthlyRem" value=$monthlyCredits}
{/if}

{if $topupRemaining !== null}
    {assign var="topupRem" value=$topupRemaining}
{else}
    {assign var="topupRem" value=$topupCredits}
{/if}

<style>
/* Theme-neutral: borders + translucent fills derived from the current text
   colour, so the cards read cleanly on light AND dark WHMCS themes without
   depending on any brand palette. */
.swz-area { margin-top: 20px; }
.swz-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 4px; }
.swz-head h3 { margin: 0; font-weight: 600; }
.swz-grid { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 20px; }
.swz-card {
    flex: 1 1 200px;
    min-width: 180px;
    border: 1px solid rgba(128,128,128,.22);
    border-radius: 10px;
    padding: 16px 16px 14px;
    background: rgba(128,128,128,.05);
}
.swz-card-label {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
    opacity: .7;
    margin: 0 0 8px;
}
.swz-num { font-size: 28px; font-weight: 700; line-height: 1.1; }
.swz-num small { font-size: 16px; font-weight: 600; opacity: .5; }
.swz-sub { font-size: 12.5px; opacity: .65; margin-top: 6px; line-height: 1.45; }
.swz-sub .swz-muted { opacity: .8; }
.swz-section-title { font-size: 13px; font-weight: 600; opacity: .6; text-transform: uppercase; letter-spacing: .04em; margin: 24px 0 0; }
.swz-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
@media (max-width: 600px) { .swz-card { flex: 1 1 100%; } }
</style>

<div class="swz-area">
    <div class="swz-head">
        <h3>Your workspace</h3>
        {if $tenantId}
            <div class="swz-actions">
                <a href="{$ssoUrl|escape}" class="btn btn-primary btn-lg">{$editorButtonLabel|default:'Open AI Editor'|escape} &rarr;</a>
            </div>
        {/if}
    </div>

    {if !$tenantId}
        <div class="alert alert-info" style="margin-top:16px;">
            Your workspace is being provisioned. If this message persists for more than a few minutes, please contact support.
        </div>
    {else}

        {* ---------- Credits: three SEPARATE pools ---------- *}
        <div class="swz-section-title">Your {$ct|escape}</div>
        <div class="swz-grid">

            {* 1) Free credits — daily allowance, resets 00:00 UTC. *}
            <div class="swz-card">
                <p class="swz-card-label">Free {$ct|escape}</p>
                {if $freeRemaining === null}
                    <div class="swz-num">&infin;</div>
                    <div class="swz-sub">Unlimited daily {$ct|escape}</div>
                {else}
                    <div class="swz-num">{$freeRemaining|string_format:"%d"}{if $freeDaily !== null}<small> / {$freeDaily|string_format:"%d"}</small>{/if}</div>
                    <div class="swz-sub">
                        {if $freeDaily !== null}{$freeDaily|string_format:"%d"}/day &middot; {/if}resets daily (00:00 UTC)
                        {if $freeMonthlyCap !== null}<br><span class="swz-muted">Up to {$freeMonthlyCap|string_format:"%d"} {$ct|escape}/month</span>{/if}
                    </div>
                {/if}
            </div>

            {* 2) Monthly credits — paid grant, resets on renewal, may roll over. *}
            <div class="swz-card">
                <p class="swz-card-label">Monthly {$ct|escape}</p>
                {if $monthlyCredits > 0}
                    <div class="swz-num">{$monthlyRem|string_format:"%d"}{if $monthlyCredits > 0}<small> / {$monthlyCredits|string_format:"%d"}</small>{/if}</div>
                    <div class="swz-sub">
                        Renews each billing cycle
                        {if $rolloverCredits !== null && $rolloverCredits > 0}<br><span class="swz-muted">+ {$rolloverCredits|string_format:"%d"} rolled over</span>
                        {elseif $rolloverMonths > 0}<br><span class="swz-muted">Unused carry over {$rolloverMonths} mo</span>{/if}
                    </div>
                {else}
                    <div class="swz-num swz-num-muted" style="opacity:.45;">&mdash;</div>
                    <div class="swz-sub">Not included on this plan</div>
                {/if}
            </div>

            {* 3) Top-up credits — one-off / purchased. *}
            <div class="swz-card">
                <p class="swz-card-label">Top-up {$ct|escape}</p>
                {if $topupCredits > 0 || ($topupRem !== null && $topupRem > 0)}
                    <div class="swz-num">{$topupRem|string_format:"%d"}{if $topupCredits > 0}<small> / {$topupCredits|string_format:"%d"}</small>{/if}</div>
                    <div class="swz-sub">
                        One-off &middot; never expires
                        {if $topupUsed !== null && $topupUsed > 0}<br><span class="swz-muted">{$topupUsed|string_format:"%d"} used</span>{/if}
                    </div>
                {else}
                    <div class="swz-num" style="opacity:.45;">0</div>
                    <div class="swz-sub">No top-up {$ct|escape} purchased</div>
                {/if}
            </div>

        </div>

        {* ---------- Plan limits + dashboard ---------- *}
        <div class="swz-section-title">Plan</div>
        <div class="swz-grid">

            {* Published projects — limit-aware. *}
            <div class="swz-card">
                <p class="swz-card-label">Published apps</p>
                {if $publishedCount !== null}
                    <div class="swz-num">{$publishedCount|string_format:"%d"}{if $publishedLimit !== null}<small> / {$publishedLimit|string_format:"%d"}</small>{/if}</div>
                    <div class="swz-sub">Live right now</div>
                {else}
                    <div class="swz-num">{if $publishedLimit !== null}{$publishedLimit|string_format:"%d"}{else}&infin;{/if}</div>
                    <div class="swz-sub">{if $publishedLimit !== null}Allowed at once{else}Unlimited{/if}</div>
                {/if}
            </div>

            {* Custom domains — limit-aware, or "not available" when disabled. *}
            <div class="swz-card">
                <p class="swz-card-label">Custom domains</p>
                {if !$customDomainsEnabled}
                    <div class="swz-num" style="opacity:.45;">&mdash;</div>
                    <div class="swz-sub">Not available on this plan</div>
                {elseif $domainsCount !== null}
                    <div class="swz-num">{$domainsCount|string_format:"%d"}{if $domainsLimit !== null}<small> / {$domainsLimit|string_format:"%d"}</small>{/if}</div>
                    <div class="swz-sub">Connected</div>
                {else}
                    <div class="swz-num">{if $domainsLimit !== null}{$domainsLimit|string_format:"%d"}{else}&infin;{/if}</div>
                    <div class="swz-sub">{if $domainsLimit !== null}Allowed{else}Unlimited{/if}</div>
                {/if}
            </div>

            {* AI spend (optional, host-toggled). *}
            {if $showAiSpend}
            <div class="swz-card">
                <p class="swz-card-label">AI spend</p>
                <div class="swz-num">${$usdCredits|default:0|string_format:"%.2f"}</div>
                <div class="swz-sub">This month</div>
            </div>
            {/if}

            {* Cloud spend (optional, host-toggled). *}
            {if $showCloudSpend}
            <div class="swz-card">
                <p class="swz-card-label">Cloud usage</p>
                <div class="swz-num">${$cloudUsd|default:0|string_format:"%.2f"}</div>
                <div class="swz-sub">This month</div>
            </div>
            {/if}

        </div>

        {if isset($usage.errorMsg) && $usage.errorMsg}
            <div class="alert alert-warning" style="margin-top:16px;">
                Couldn&rsquo;t refresh usage just now: {$usage.errorMsg|escape}
            </div>
        {/if}

        {if $supportUrl}
            <div class="swz-sub" style="margin-top:16px;">
                Need help? <a href="{$supportUrl|escape}" target="_blank" rel="noopener">Contact support</a>.
            </div>
        {/if}
    {/if}
</div>
