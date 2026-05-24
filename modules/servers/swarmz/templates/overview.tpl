{*
 * Swarmz client-area overview template.
 *
 * Provided variables (set by swarmz_ClientArea):
 *   tenantId       string|null
 *   dashboardUrl   string|null
 *   ssoUrl         string  — WHMCS SSO trigger URL
 *   usage          array   — from swarmz_UsageUpdate
 *   creditsUsed    int
 *   creditsLimit   int|null  — null = unlimited
 *   cloudUsd       float
 *   usdCredits     float
 *   projectsCount  int
 *
 *   Host-configurable via the Swarmz Reseller Console addon module:
 *   editorButtonLabel  string  — SSO button text
 *   creditTerm         string  — what to call "credits"
 *   showAiSpend        bool    — show AI USD spend card
 *   showCloudSpend     bool    — show cloud USD spend card
 *   supportUrl         string  — optional host support link
 *}

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
            <div class="col-sm-3">
                <div style="background:#f6f8fb;padding:14px;border-radius:8px;text-align:center;">
                    <div style="font-size:24px;font-weight:600;">{$creditsUsed|default:0}</div>
                    <div style="color:#666;font-size:13px;">
                        {$creditTerm|default:'credits'|escape} used {if $creditsLimit !== null}<br>of {$creditsLimit|escape}{else}<br>(unlimited){/if}
                    </div>
                </div>
            </div>
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
                    <div style="font-size:24px;font-weight:600;">${$cloudUsd|string_format:"%.2f"}</div>
                    <div style="color:#666;font-size:13px;">Cloud usage this month</div>
                </div>
            </div>
            {/if}
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
