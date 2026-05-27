{*
 * Client-area overview template.
 *
 * Deliberately UNBRANDED: no "swarmz" marks here — the host resells this under
 * their own brand. The SSO button label, the word used for "credits", and which
 * spend figures show are all host-configurable via the Reseller Console addon.
 *
 * Provided variables (set by swarmz_ClientArea):
 *   tenantId             string|null
 *   dashboardUrl         string|null
 *   ssoUrl               string  — WHMCS SSO trigger URL
 *   usage                array   — from swarmz_UsageUpdate
 *   creditsUsed          float
 *   creditsLimit         int|null   — null = unlimited
 *   monthlyCredits       int        — paid monthly grant (0 = none)
 *   cloudUsd             float
 *   usdCredits           float
 *   projectsCount        int|null
 *   domainsCount         int|null   — live count (often null; API doesn't return it)
 *   domainsLimit         int|null   — null = unlimited
 *   publishedCount       int|null
 *   publishedLimit       int|null   — null = unlimited
 *   customDomainsEnabled bool
 *
 *   Host-configurable via the Reseller Console addon module:
 *   editorButtonLabel  string  — SSO button text
 *   creditTerm         string  — what to call "credits"
 *   showAiSpend        bool    — show AI USD spend card
 *   showCloudSpend     bool    — show cloud USD spend card
 *   supportUrl         string  — optional host support link
 *}

{* Credits remaining = paid monthly grant (or soft cap) minus used; never below 0. *}
{assign var="creditsBudget" value=0}
{if $monthlyCredits > 0}
    {assign var="creditsBudget" value=$monthlyCredits}
{elseif $creditsLimit !== null}
    {assign var="creditsBudget" value=$creditsLimit}
{/if}
{assign var="creditsRemaining" value=$creditsBudget-$creditsUsed}
{if $creditsRemaining < 0}{assign var="creditsRemaining" value=0}{/if}

<div class="swarmz-clientarea" style="margin-top:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <h3 style="margin:0;">Your workspace</h3>
        {if $tenantId}
            <a href="{$ssoUrl|escape}" class="btn btn-primary btn-lg">{$editorButtonLabel|default:'Open AI Editor'|escape} &rarr;</a>
        {/if}
    </div>

    {if !$tenantId}
        <div class="alert alert-info" style="margin-top:16px;">
            Your workspace is being provisioned. If this message persists for more than a few minutes, please contact support.
        </div>
    {else}
        <div class="row" style="margin-top:24px;">
            {* Credits — show remaining when there's a budget, else just "used". *}
            <div class="col-sm-3">
                <div style="background:#f6f8fb;padding:14px;border-radius:8px;text-align:center;">
                    {if $creditsBudget > 0}
                        <div style="font-size:24px;font-weight:600;">{$creditsRemaining|string_format:"%d"}</div>
                        <div style="color:#666;font-size:13px;">
                            {$creditTerm|default:'credits'|escape} remaining<br>
                            <span style="color:#999;">{$creditsUsed|string_format:"%d"} of {$creditsBudget|string_format:"%d"} used</span>
                        </div>
                    {else}
                        <div style="font-size:24px;font-weight:600;">{$creditsUsed|default:0|string_format:"%d"}</div>
                        <div style="color:#666;font-size:13px;">
                            {$creditTerm|default:'credits'|escape} used<br>(unlimited)
                        </div>
                    {/if}
                </div>
            </div>

            {* Published projects — limit-aware. Show count/limit when a count is
               available, otherwise just the allowance. *}
            <div class="col-sm-3">
                <div style="background:#f6f8fb;padding:14px;border-radius:8px;text-align:center;">
                    {if $publishedCount !== null}
                        <div style="font-size:24px;font-weight:600;">
                            {$publishedCount|string_format:"%d"}{if $publishedLimit !== null}<span style="color:#999;font-size:16px;"> / {$publishedLimit|string_format:"%d"}</span>{/if}
                        </div>
                        <div style="color:#666;font-size:13px;">published projects</div>
                    {else}
                        <div style="font-size:24px;font-weight:600;">{if $publishedLimit !== null}{$publishedLimit|string_format:"%d"}{else}&infin;{/if}</div>
                        <div style="color:#666;font-size:13px;">published projects {if $publishedLimit !== null}allowed{else}(unlimited){/if}</div>
                    {/if}
                </div>
            </div>

            {* Custom domains — limit-aware, or "not available" when disabled. *}
            <div class="col-sm-3">
                <div style="background:#f6f8fb;padding:14px;border-radius:8px;text-align:center;">
                    {if !$customDomainsEnabled}
                        <div style="font-size:18px;font-weight:600;color:#999;">Not available</div>
                        <div style="color:#666;font-size:13px;">custom domains</div>
                    {elseif $domainsCount !== null}
                        <div style="font-size:24px;font-weight:600;">
                            {$domainsCount|string_format:"%d"}{if $domainsLimit !== null}<span style="color:#999;font-size:16px;"> / {$domainsLimit|string_format:"%d"}</span>{/if}
                        </div>
                        <div style="color:#666;font-size:13px;">custom domains</div>
                    {else}
                        <div style="font-size:24px;font-weight:600;">{if $domainsLimit !== null}{$domainsLimit|string_format:"%d"}{else}&infin;{/if}</div>
                        <div style="color:#666;font-size:13px;">custom domains {if $domainsLimit !== null}allowed{else}(unlimited){/if}</div>
                    {/if}
                </div>
            </div>

            <div class="col-sm-3">
                <div style="background:#f6f8fb;padding:14px;border-radius:8px;text-align:center;">
                    {if $dashboardUrl}
                        <a href="{$dashboardUrl|escape}" target="_blank" rel="noopener" class="btn btn-default" style="margin-top:6px;">
                            Open dashboard in new tab
                        </a>
                    {else}
                        <div style="color:#999;">Dashboard link unavailable</div>
                    {/if}
                </div>
            </div>
        </div>

        {* Spend figures (optional, host-toggled). *}
        {if $showAiSpend || $showCloudSpend}
        <div class="row" style="margin-top:12px;">
            {if $showAiSpend}
            <div class="col-sm-3">
                <div style="background:#f6f8fb;padding:14px;border-radius:8px;text-align:center;">
                    <div style="font-size:24px;font-weight:600;">${$usdCredits|default:0|string_format:"%.2f"}</div>
                    <div style="color:#666;font-size:13px;">AI spend this month</div>
                </div>
            </div>
            {/if}
            {if $showCloudSpend}
            <div class="col-sm-3">
                <div style="background:#f6f8fb;padding:14px;border-radius:8px;text-align:center;">
                    <div style="font-size:24px;font-weight:600;">${$cloudUsd|default:0|string_format:"%.2f"}</div>
                    <div style="color:#666;font-size:13px;">Cloud usage this month</div>
                </div>
            </div>
            {/if}
        </div>
        {/if}

        {if isset($usage.errorMsg) && $usage.errorMsg}
            <div class="alert alert-warning" style="margin-top:16px;">
                Couldn&rsquo;t refresh usage just now: {$usage.errorMsg|escape}
            </div>
        {/if}

        {if $supportUrl}
            <div style="margin-top:16px;font-size:13px;color:#666;">
                Need help? <a href="{$supportUrl|escape}" target="_blank" rel="noopener">Contact support</a>.
            </div>
        {/if}
    {/if}
</div>
