{*
 * Existing-customer workspace chooser (v1.26.0) — rendered by
 * swarmz_clientarea() at index.php?m=swarmz&a=choose when the host's
 * "Existing Customer Workspace" policy is `ask`.
 *
 * Unbranded by design: this is the host's client area. Every string comes
 * from the L array (modules/addons/swarmz/lang/<language>.php, English
 * fallback per key). The editor label is the host's own (Reseller Console →
 * Editor Button Label).
 *}
<style>
.swz-choose { max-width: 760px; margin: 10px 0 30px; }
.swz-choose .swz-choose-lede { opacity: .7; margin: 0 0 18px; line-height: 1.5; }
.swz-choose .swz-choose-prompt { margin: 0 0 18px; padding: 10px 14px; border-radius: 10px; background: rgba(0,0,0,.04); font-size: 13px; line-height: 1.45; max-height: 72px; overflow: auto; word-break: break-word; }
.swz-choose .swz-choose-prompt b { font-weight: 600; }
.swz-choose .swz-choose-list { list-style: none; margin: 0; padding: 0; }
.swz-choose .swz-choose-item { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 16px; border: 1px solid rgba(0,0,0,.1); border-radius: 12px; margin: 0 0 10px; background: #fff; }
.swz-choose .swz-choose-name { font-weight: 600; margin: 0; }
.swz-choose .swz-choose-meta { font-size: 12.5px; opacity: .65; margin: 3px 0 0; }
.swz-choose form { margin: 0; }
.swz-choose .swz-choose-new { margin-top: 22px; padding-top: 18px; border-top: 1px solid rgba(0,0,0,.1); display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.swz-choose .swz-choose-new p { margin: 0; opacity: .7; }
.swz-choose .swz-choose-error { margin: 0 0 16px; }
</style>
<div class="swz-choose">
    <p class="swz-choose-lede">{$L.choose_lede|escape}</p>
    {if $errorMessage}
        <div class="alert alert-warning swz-choose-error">{$errorMessage|escape}</div>
    {/if}
    {if $promptExcerpt}
        <div class="swz-choose-prompt"><b>{$L.choose_building|escape}</b> {$promptExcerpt|escape}</div>
    {/if}

    {if $workspaces}
        <ul class="swz-choose-list">
        {foreach from=$workspaces item=ws}
            <li class="swz-choose-item">
                <div>
                    <p class="swz-choose-name">{$ws.product|escape}{if $ws.domain} &mdash; {$ws.domain|escape}{/if}</p>
                    <p class="swz-choose-meta">{$L.choose_since|escape} {$ws.regdate|escape}{if $ws.last_launch} &middot; {$L.choose_last_opened|escape} {$ws.last_launch|escape}{/if}</p>
                </div>
                <form method="post" action="{$chooserUrl|escape}">
                    <input type="hidden" name="token" value="{$token}" />
                    <input type="hidden" name="swz_ec_action" value="open" />
                    <input type="hidden" name="serviceid" value="{$ws.id}" />
                    <button type="submit" class="btn btn-primary" title="{$editorButtonLabel|escape}">{$L.choose_build_here|escape} &rarr;</button>
                </form>
            </li>
        {/foreach}
        </ul>
    {else}
        <p>{$L.choose_none|escape}</p>
    {/if}

    <div class="swz-choose-new">
        <p>{$L.choose_new_lede|escape}</p>
        <form method="post" action="{$chooserUrl|escape}">
            <input type="hidden" name="token" value="{$token}" />
            <input type="hidden" name="swz_ec_action" value="new" />
            <button type="submit" class="btn btn-default">{$L.choose_new|escape}</button>
        </form>
    </div>
</div>
