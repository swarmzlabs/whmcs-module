{*
 * Client-area overview template (redesigned in 1.9.0).
 *
 * Deliberately UNBRANDED: no "swarmz" marks here — the host resells this under
 * their own brand. The SSO button label, the word used for "credits", and the
 * support link are all host-configurable via the Reseller Console addon.
 *
 * Credits are shown as SEPARATE pools — never one merged number, never in USD
 * (dollar figures would leak internal cost/profit to the host's customer):
 *   1. Free credits    — granted per the plan's cadence (daily / monthly / one-time).
 *   2. Monthly credits — paid build grant, resets on the billing cycle, may roll over.
 *   3. Cloud credits   — cloud lane; renews per cycle OR a one-time grant.
 *   4. AI credits      — AI lane; renews per cycle OR a one-time grant.
 *
 * Values + plan caps come entirely from the platform-usage API response; all
 * arithmetic (formatting, remaining percentages) is done in PHP — the template
 * only branches and prints.
 *
 * Provided variables: see swarmz_ClientArea() in ../swarmz.php. New in 1.9.0:
 *   freePct / monthlyPct / cloudPct / aiPct  int|null — remaining % (null = no bar)
 *}

{assign var="ct" value=$creditTerm|default:'credits'}

{if $monthlyRemaining !== null}
    {assign var="monthlyRem" value=$monthlyRemaining}
{else}
    {assign var="monthlyRem" value=$monthlyCredits}
{/if}

<style>
/* Theme-neutral: every tone is derived from the surrounding theme's text
   colour (currentColor + gray alphas), so the panel reads cleanly on light
   AND dark WHMCS themes without depending on any palette. The one brand
   accent — the launch button — inherits the theme's own btn-primary. */
.swz-area { margin-top: 22px; }
.swz-hero {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    border: 1px solid rgba(128,128,128,.20);
    border-radius: 16px;
    padding: 20px 22px;
    background:
        radial-gradient(1200px 240px at 0% 0%, rgba(128,128,128,.10), transparent 60%),
        rgba(128,128,128,.04);
}
.swz-hero-title { margin: 0; font-size: 19px; font-weight: 700; letter-spacing: -.01em; }
.swz-hero-sub { margin: 5px 0 0; font-size: 13px; opacity: .62; display: flex; align-items: center; gap: 7px; }
.swz-live-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #30a46c; box-shadow: 0 0 0 3px rgba(48,164,108,.18);
    display: inline-block; flex: 0 0 auto;
}
.swz-launch {
    font-size: 15px !important; font-weight: 600 !important;
    padding: 12px 22px !important; border-radius: 12px !important;
    display: inline-flex !important; align-items: center; gap: 9px;
    box-shadow: 0 6px 18px rgba(0,0,0,.14);
    transition: transform .15s ease, box-shadow .15s ease;
}
.swz-launch:hover { transform: translateY(-1px); box-shadow: 0 9px 24px rgba(0,0,0,.18); }
.swz-launch .swz-arrow { transition: transform .15s ease; display: inline-block; }
.swz-launch:hover .swz-arrow { transform: translateX(3px); }
.swz-section-title {
    font-size: 12px; font-weight: 700; opacity: .55;
    text-transform: uppercase; letter-spacing: .07em; margin: 26px 0 10px;
}
.swz-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(215px, 1fr)); gap: 14px; }
.swz-card {
    border: 1px solid rgba(128,128,128,.20);
    border-radius: 14px;
    padding: 16px 17px 15px;
    background: rgba(128,128,128,.045);
    display: flex; flex-direction: column; gap: 10px;
    min-width: 0;
}
.swz-card-label {
    font-size: 11.5px; font-weight: 700; letter-spacing: .05em;
    text-transform: uppercase; opacity: .62; margin: 0;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.swz-cadence {
    font-size: 10.5px; font-weight: 600; letter-spacing: .02em; text-transform: none;
    border: 1px solid rgba(128,128,128,.28); border-radius: 999px;
    padding: 2px 8px; opacity: .75; white-space: nowrap;
}
.swz-num { font-size: 27px; font-weight: 750; line-height: 1.05; letter-spacing: -.01em; }
.swz-num small { font-size: 15px; font-weight: 600; opacity: .45; }
.swz-num-muted { opacity: .4; }
.swz-bar { height: 5px; border-radius: 999px; background: rgba(128,128,128,.16); overflow: hidden; }
.swz-bar-fill { height: 100%; border-radius: 999px; background: currentColor; opacity: .55; }
.swz-low { color: #e5484d; }
.swz-low .swz-bar-fill { opacity: .8; }
.swz-sub { font-size: 12.5px; opacity: .62; line-height: 1.45; margin: 0; }
.swz-sub .swz-muted { opacity: .8; }
.swz-footer { margin-top: 18px; font-size: 12.5px; opacity: .65; }
@media (max-width: 640px) {
    .swz-hero { padding: 16px; }
    .swz-launch { width: 100%; justify-content: center; }
    .swz-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 420px) {
    .swz-grid { grid-template-columns: 1fr; }
}
</style>

<div class="swz-area">

    {* ---------- Hero: workspace status + launch ---------- *}
    <div class="swz-hero">
        <div>
            <h3 class="swz-hero-title">Your workspace</h3>
            {if $tenantId}
                <p class="swz-hero-sub"><span class="swz-live-dot"></span> Ready — jump back into the editor any time.</p>
            {else}
                <p class="swz-hero-sub">Being prepared…</p>
            {/if}
        </div>
        {if $tenantId}
            {* SSO launcher: POST to the module's custom action (swarmz_launch),
               which re-mints a fresh sign-on redirect on EVERY click. Opens in
               a new tab so repeat launches are never suppressed by bfcache /
               WHMCS's built-in dosinglesignon idempotency. *}
            <form action="clientarea.php?action=productdetails" method="post" target="_blank" rel="noopener" style="display:inline;">
                <input type="hidden" name="id" value="{$serviceId}" />
                <input type="hidden" name="modop" value="custom" />
                <input type="hidden" name="a" value="launch" />
                <button type="submit" class="btn btn-primary swz-launch">{$editorButtonLabel|default:'Open AI Editor'|escape} <span class="swz-arrow">&rarr;</span></button>
            </form>
        {/if}
    </div>

    {if !$tenantId}
        <div class="alert alert-info" style="margin-top:16px;">
            Your workspace is being provisioned. If this message persists for more than a few minutes, please contact support.
        </div>
    {else}

        {* ---------- Credits: four SEPARATE pools with progress bars ---------- *}
        <div class="swz-section-title">Your {$ct|escape}</div>
        <div class="swz-grid">

            {* 1) Free credits — copy follows the plan's grant cadence. *}
            <div class="swz-card{if $freePct !== null && $freePct <= 12} swz-low{/if}">
                <p class="swz-card-label">Free {$ct|escape}
                    {if $freeKind === 'daily'}<span class="swz-cadence">Daily</span>
                    {elseif $freeKind === 'monthly'}<span class="swz-cadence">Monthly</span>
                    {elseif $freeKind === 'one_time'}<span class="swz-cadence">One-time</span>{/if}
                </p>
                {if $freeKind === 'none'}
                    <div class="swz-num swz-num-muted">&mdash;</div>
                    <p class="swz-sub">Not included on this plan</p>
                {elseif $freeKind === 'unlimited'}
                    <div class="swz-num">&infin;</div>
                    <p class="swz-sub">Unlimited {$ct|escape}</p>
                {else}
                    <div class="swz-num">{$freeRemainingFmt}<small> / {$freeTotalFmt}</small></div>
                    {if $freePct !== null}
                        <div class="swz-bar"><div class="swz-bar-fill" style="width:{$freePct}%;"></div></div>
                    {/if}
                    {if $freeKind === 'one_time'}
                        <p class="swz-sub">One-time allowance &middot; does not renew</p>
                    {elseif $freeKind === 'monthly'}
                        <p class="swz-sub">Replenishes monthly</p>
                    {else}
                        <p class="swz-sub">{$freeTotalFmt}/day &middot; resets daily (00:00 UTC)
                            {if $freeMonthlyCap !== null && $freeMonthlyCap > 0}<br><span class="swz-muted">Up to {$freeMonthlyCap|string_format:"%d"} {$ct|escape}/month</span>{/if}
                        </p>
                    {/if}
                {/if}
            </div>

            {* 2) Monthly credits — paid grant, resets on renewal, may roll over. *}
            <div class="swz-card{if $monthlyPct !== null && $monthlyPct <= 12} swz-low{/if}">
                <p class="swz-card-label">Monthly {$ct|escape}
                    {if $monthlyCredits > 0}<span class="swz-cadence">Per cycle</span>{/if}
                </p>
                {if $monthlyCredits > 0}
                    <div class="swz-num">{$monthlyRem|string_format:"%d"}<small> / {$monthlyCredits|string_format:"%d"}</small></div>
                    {if $monthlyPct !== null}
                        <div class="swz-bar"><div class="swz-bar-fill" style="width:{$monthlyPct}%;"></div></div>
                    {/if}
                    <p class="swz-sub">
                        Renews each billing cycle
                        {if $rolloverCredits !== null && $rolloverCredits > 0}<br><span class="swz-muted">+ {$rolloverCredits|string_format:"%d"} rolled over</span>
                        {elseif $rolloverMonths > 0}<br><span class="swz-muted">Unused carry over {$rolloverMonths} mo</span>{/if}
                    </p>
                {else}
                    <div class="swz-num swz-num-muted">&mdash;</div>
                    <p class="swz-sub">Not included on this plan</p>
                {/if}
            </div>

            {* 3) Cloud credits — separate lane; cadence follows the plan. *}
            <div class="swz-card{if $cloudPct !== null && $cloudPct <= 12} swz-low{/if}">
                <p class="swz-card-label">Cloud {$ct|escape}
                    {if $cloudMode === 'one_time'}<span class="swz-cadence">One-time</span>
                    {elseif $cloudGrant > 0}<span class="swz-cadence">Per cycle</span>{/if}
                </p>
                {if $cloudMode === 'none' || !($cloudGrant > 0)}
                    <div class="swz-num swz-num-muted">&mdash;</div>
                    <p class="swz-sub">Not included on this plan</p>
                {else}
                    <div class="swz-num">{$cloudGrantRemaining|string_format:"%d"}<small> / {$cloudGrant|string_format:"%d"}</small></div>
                    {if $cloudPct !== null}
                        <div class="swz-bar"><div class="swz-bar-fill" style="width:{$cloudPct}%;"></div></div>
                    {/if}
                    <p class="swz-sub">{if $cloudMode === 'one_time'}One-time allowance &middot; does not renew{else}Renews each billing cycle{/if}</p>
                {/if}
            </div>

            {* 4) AI credits — separate lane; cadence follows the plan. *}
            <div class="swz-card{if $aiPct !== null && $aiPct <= 12} swz-low{/if}">
                <p class="swz-card-label">AI {$ct|escape}
                    {if $aiMode === 'one_time'}<span class="swz-cadence">One-time</span>
                    {elseif $aiGrant > 0}<span class="swz-cadence">Per cycle</span>{/if}
                </p>
                {if $aiMode === 'none' || !($aiGrant > 0)}
                    <div class="swz-num swz-num-muted">&mdash;</div>
                    <p class="swz-sub">Not included on this plan</p>
                {else}
                    <div class="swz-num">{$aiGrantRemaining|string_format:"%d"}<small> / {$aiGrant|string_format:"%d"}</small></div>
                    {if $aiPct !== null}
                        <div class="swz-bar"><div class="swz-bar-fill" style="width:{$aiPct}%;"></div></div>
                    {/if}
                    <p class="swz-sub">{if $aiMode === 'one_time'}One-time allowance &middot; does not renew{else}Renews each billing cycle{/if}</p>
                {/if}
            </div>

        </div>

        {* ---------- Plan limits ---------- *}
        <div class="swz-section-title">Plan</div>
        <div class="swz-grid">

            {* Published projects — limit-aware. *}
            <div class="swz-card">
                <p class="swz-card-label">Published apps</p>
                {if $publishedCount !== null}
                    <div class="swz-num">{$publishedCount|string_format:"%d"}{if $publishedLimit !== null}<small> / {$publishedLimit|string_format:"%d"}</small>{/if}</div>
                    <p class="swz-sub">Live right now</p>
                {else}
                    <div class="swz-num">{if $publishedLimit !== null}{$publishedLimit|string_format:"%d"}{else}&infin;{/if}</div>
                    <p class="swz-sub">{if $publishedLimit !== null}Allowed at once{else}Unlimited{/if}</p>
                {/if}
            </div>

            {* Custom domains — limit-aware, or "not available" when disabled. *}
            <div class="swz-card">
                <p class="swz-card-label">Custom domains</p>
                {if !$customDomainsEnabled}
                    <div class="swz-num swz-num-muted">&mdash;</div>
                    <p class="swz-sub">Not available on this plan</p>
                {elseif $domainsCount !== null}
                    <div class="swz-num">{$domainsCount|string_format:"%d"}{if $domainsLimit !== null}<small> / {$domainsLimit|string_format:"%d"}</small>{/if}</div>
                    <p class="swz-sub">Connected</p>
                {else}
                    <div class="swz-num">{if $domainsLimit !== null}{$domainsLimit|string_format:"%d"}{else}&infin;{/if}</div>
                    <p class="swz-sub">{if $domainsLimit !== null}Allowed{else}Unlimited{/if}</p>
                {/if}
            </div>

            {* NOTE: USD spend cards were removed deliberately. The customer must
               never see dollar amounts (they leak internal cost/profit) — all
               spend is shown as CREDITS in the section above. *}

        </div>

        {if isset($usage.errorMsg) && $usage.errorMsg}
            <div class="alert alert-warning" style="margin-top:16px;">
                Couldn&rsquo;t refresh usage just now: {$usage.errorMsg|escape}
            </div>
        {/if}

        {if $supportUrl}
            <div class="swz-footer">
                Need help? <a href="{$supportUrl|escape}" target="_blank" rel="noopener">Contact support</a>.
            </div>
        {/if}
    {/if}
</div>
