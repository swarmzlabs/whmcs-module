<?php
/**
 * Swarmz Reseller Console — admin dashboard renderer.
 *
 * Joins WHMCS's own service records (which customer, which product/plan, which
 * tenant) to ONE account-wide /platform-usage call (live credit + cloud
 * spend per workspace) and renders the result. The usage call returns
 * usage.by_workspace[] keyed by workspace_id, which is exactly the tenant_id
 * the provisioning module stores on each WHMCS service — so the join is a
 * straight map, and the whole table costs a single API round-trip regardless
 * of customer count.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Addon\Swarmz;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

// Reuse the provisioning (server) module's tested API client + helpers so the
// HTTP contract has a single source of truth. They ship together in the same
// release ZIP; if the server module is absent the console degrades gracefully.
$swarmzServerLib = __DIR__ . '/../../../servers/swarmz/lib';
if (is_file($swarmzServerLib . '/Api.php')) {
    require_once $swarmzServerLib . '/Exceptions.php';
    require_once $swarmzServerLib . '/Api.php';
    require_once $swarmzServerLib . '/Helpers.php';
}

require_once __DIR__ . '/PromptBox.php';

class Console
{
    /** Custom-field name the provisioning module writes the tenant id to. */
    const CUSTOM_FIELD_TENANT_ID = 'Swarmz Tenant ID';

    /** @var array */
    private $vars;
    /** @var string */
    private $modulelink;
    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var bool */
    private $serverLibAvailable;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
        $this->modulelink = isset($vars['modulelink']) && $vars['modulelink'] !== ''
            ? (string) $vars['modulelink']
            : 'addonmodules.php?module=swarmz';
        $this->apiKey = isset($vars['API Key']) ? trim((string) $vars['API Key']) : '';
        $base = isset($vars['API Base URL']) ? trim((string) $vars['API Base URL']) : '';
        $this->baseUrl = $base !== '' ? rtrim($base, '/') : 'https://api.swarmz.net';
        $this->serverLibAvailable = class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Api');
    }

    public function render(): string
    {
        $action = isset($_REQUEST['swarmz_action']) ? (string) $_REQUEST['swarmz_action'] : '';
        $period = $this->safePeriod(isset($_REQUEST['period']) ? (string) $_REQUEST['period'] : '');

        $out = $this->styles();
        $out .= '<div class="swarmz-console">';
        $out .= '<div class="swz-head">'
            . '<div><h2 class="swz-title">Swarmz Reseller Console</h2>'
            . '<p class="swz-sub">Which customer is on which plan, and their live credit + cloud usage. '
            . 'All money shown is your <strong>wholesale</strong> cost from Swarmz &mdash; set your retail price in WHMCS product pricing.</p></div>'
            . '</div>';

        if (!$this->serverLibAvailable) {
            return $out . $this->notice('warning',
                'The Swarmz <strong>provisioning (server) module</strong> was not found at '
                . '<code>modules/servers/swarmz/</code>. Install it alongside this console &mdash; '
                . 'the console reuses its API client and reads the tenant ids it stores on each service.'
            ) . '</div>';
        }

        if ($this->apiKey === '') {
            return $out . $this->notice('info',
                'No API key set yet. Add your <code>sk_live_…</code> key under '
                . '<strong>Addons &rarr; Swarmz Reseller Console &rarr; Configure &rarr; API Key</strong>, then reload this page.'
            ) . '</div>';
        }

        if ($action === 'testconn') {
            $out .= $this->renderTestConnection($period);
        }

        // Dedicated "Plans" view — lists the account's named plans and their
        // entitlements. A self-contained page (its own back link) so it doesn't
        // need the usage round-trip the dashboard does.
        if ($action === 'plans') {
            $out .= $this->renderPlans();
            $out .= '</div>';
            return $out;
        }

        // Dedicated "Prompt Box" view — the embeddable storefront widget:
        // snippet builder + live preview + recently captured prompts. Fully
        // local (WHMCS DB only), no Swarmz API round-trip needed.
        if ($action === 'promptbox') {
            $out .= $this->renderPromptBox();
            $out .= '</div>';
            return $out;
        }

        try {
            $services = $this->gatherServices();
            $usage = $this->fetchUsage($period);
        } catch (\Throwable $e) {
            $out .= $this->renderToolbar($period);
            $out .= $this->notice('danger',
                'Could not load usage from the Swarmz API: <code>' . $this->esc($this->scrub($e->getMessage())) . '</code>'
            );
            return $out . '</div>';
        }

        // Consolidated billing summary (purchased vs consumed, rollover/balance,
        // cloud spend vs cap). Best-effort + auth-aware: see fetchBillingSummary.
        $billing = $this->fetchBillingSummary();

        $out .= $this->renderToolbar($period);
        $out .= $this->renderSummary($services, $usage, $period);
        $out .= $this->renderBillingSummary($billing, $usage, $services);
        $out .= $this->renderTable($services, $usage);
        $out .= '</div>';
        return $out;
    }

    // -------------------------------------------------------------- data

    /**
     * Every WHMCS service that has a Swarmz tenant id stored on it, with its
     * client + product. One query, no per-service API calls.
     *
     * @return array<int,\stdClass>
     */
    private function gatherServices(): array
    {
        $rows = Capsule::table('tblhosting as h')
            ->join('tblcustomfieldsvalues as cfv', 'cfv.relid', '=', 'h.id')
            ->join('tblcustomfields as cf', function ($join) {
                $join->on('cf.id', '=', 'cfv.fieldid')
                    ->where('cf.type', '=', 'product')
                    ->where('cf.fieldname', '=', self::CUSTOM_FIELD_TENANT_ID);
            })
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('tblclients as cl', 'cl.id', '=', 'h.userid')
            ->where('cfv.value', '!=', '')
            ->whereNotNull('cfv.value')
            ->orderBy('h.id', 'desc')
            ->get([
                'h.id as serviceid',
                'h.userid as clientid',
                'h.domainstatus as status',
                'h.domain as domain',
                'p.name as productname',
                'cfv.value as tenantid',
                'cl.firstname as firstname',
                'cl.lastname as lastname',
                'cl.companyname as companyname',
            ]);

        // Normalize to a plain array.
        $out = [];
        foreach ($rows as $r) {
            $out[] = $r;
        }
        return $out;
    }

    /**
     * One account-wide /platform-usage call. Returns:
     *   - map:    tenant_id => USD spend { credits, ai, cloud } (the host's
     *             WHOLESALE cost — kept for the one "Wholesale cost" total).
     *   - bal:    tenant_id => per-lane CREDITS standing { build/cloud/ai
     *             used+total (+rollover/topup remaining on build) }, drawn from
     *             `balances.by_workspace[]`. This is what the console now shows
     *             per workspace (credits, not USD).
     *   - caps:   tenant_id => plan-caps map (balances.by_workspace[].caps) —
     *             the only source of plan limits + the per-lane grant TOTALS
     *             (monthly_cloud_credits / monthly_ai_credits).
     *   - totals: account-wide USD spend for the period.
     *
     * @return array{map:array<string,array>,bal:array<string,array>,caps:array<string,array>,totals:array,period:array}
     */
    private function fetchUsage(string $period): array
    {
        /** @var \WHMCS\Module\Server\Swarmz\Api $api */
        $api = new \WHMCS\Module\Server\Swarmz\Api($this->apiKey, $this->baseUrl);
        $res = $api->postPlatform('platform-usage', ['period' => $period]);
        $usage = (isset($res['body']['usage']) && is_array($res['body']['usage'])) ? $res['body']['usage'] : [];
        $balances = (isset($res['body']['balances']) && is_array($res['body']['balances'])) ? $res['body']['balances'] : [];

        $map = [];
        $byWs = isset($usage['by_workspace']) && is_array($usage['by_workspace']) ? $usage['by_workspace'] : [];
        foreach ($byWs as $w) {
            if (empty($w['workspace_id'])) {
                continue;
            }
            $map[(string) $w['workspace_id']] = [
                'credits' => (float) ($w['credits_used'] ?? 0),
                'ai'      => (float) ($w['usd_credits'] ?? 0),
                'cloud'   => (float) ($w['cloud_usd'] ?? 0),
            ];
        }

        // Per-workspace plan caps + per-lane CREDIT standing from the balances
        // section. Both keyed by workspace_id (= the tenant_id on each service).
        $caps = [];
        $bal = [];
        $byWsBal = isset($balances['by_workspace']) && is_array($balances['by_workspace']) ? $balances['by_workspace'] : [];
        foreach ($byWsBal as $w) {
            if (empty($w['workspace_id'])) {
                continue;
            }
            $tid = (string) $w['workspace_id'];
            $wCaps = (isset($w['caps']) && is_array($w['caps'])) ? $w['caps'] : [];
            if (!empty($wCaps)) {
                $caps[$tid] = $wCaps;
            }
            $bal[$tid] = self::laneCreditsFromWorkspace($w, $wCaps);
        }

        return [
            'map'    => $map,
            'bal'    => $bal,
            'caps'   => $caps,
            'totals' => [
                'credits' => (float) ($usage['credits_used'] ?? 0),
                'ai'      => (float) ($usage['usd_credits'] ?? 0),
                'cloud'   => (float) ($usage['cloud_usd'] ?? 0),
            ],
            'period' => (isset($usage['period']) && is_array($usage['period'])) ? $usage['period'] : [],
        ];
    }

    /**
     * Distil one balances.by_workspace[] row into the per-lane CREDITS view the
     * console shows (used / total per lane, in credits — never USD). Mirrors the
     * server module's client-area credit mapping so the host and their customer
     * read the same numbers.
     *
     *   build : included_used / included_credits, plus rollover + top-up
     *           REMAINING (the extra credits available beyond the monthly grant).
     *   cloud : (monthly_cloud_credits − cloud_grant_remaining) / monthly_cloud_credits.
     *   ai    : (monthly_ai_credits    − ai_grant_remaining)    / monthly_ai_credits.
     *
     * A missing total is returned as null (→ "—"); a missing "used" as 0.
     *
     * @param array $w    One balances.by_workspace[] row.
     * @param array $caps That row's caps (grant totals live here for cloud/ai).
     * @return array{
     *   buildUsed:float, buildTotal:?float, rolloverRemaining:float, topupRemaining:float,
     *   cloudUsed:float, cloudTotal:?float, aiUsed:float, aiTotal:?float
     * }
     */
    private static function laneCreditsFromWorkspace(array $w, array $caps): array
    {
        $num = static function ($v): ?float {
            return is_numeric($v) ? (float) $v : null;
        };

        // ---- Build (monthly included) lane ----
        $buildTotal = $num($w['included_credits'] ?? null);
        $buildUsed  = (float) ($num($w['included_used'] ?? null) ?? 0.0);

        // Rollover remaining (carried-over credits still available).
        $rollRemaining = $num($w['rollover_remaining'] ?? null) ?? 0.0;

        // Top-up remaining (one-off purchased credits still available).
        $topupRemaining = $num($w['topup_available'] ?? null);
        if ($topupRemaining === null) {
            $purchased = $num($w['purchased_total'] ?? null);
            $consumed  = $num($w['purchased_consumed'] ?? null);
            $topupRemaining = ($purchased !== null)
                ? max(0.0, $purchased - ($consumed ?? 0.0))
                : 0.0;
        }

        // ---- Cloud lane: total from caps, used = total − remaining ----
        $cloudTotal = $num($caps['monthly_cloud_credits'] ?? null);
        $cloudRemaining = $num($w['cloud_grant_remaining'] ?? null);
        $cloudUsed = ($cloudTotal !== null && $cloudRemaining !== null)
            ? max(0.0, $cloudTotal - $cloudRemaining)
            : 0.0;

        // ---- AI lane: total from caps, used = total − remaining ----
        $aiTotal = $num($caps['monthly_ai_credits'] ?? null);
        $aiRemaining = $num($w['ai_grant_remaining'] ?? null);
        $aiUsed = ($aiTotal !== null && $aiRemaining !== null)
            ? max(0.0, $aiTotal - $aiRemaining)
            : 0.0;

        return [
            'buildUsed'         => $buildUsed,
            'buildTotal'        => $buildTotal,
            'rolloverRemaining' => $rollRemaining,
            'topupRemaining'    => (float) $topupRemaining,
            'cloudUsed'         => $cloudUsed,
            'cloudTotal'        => $cloudTotal,
            'aiUsed'            => $aiUsed,
            'aiTotal'           => $aiTotal,
        ];
    }

    /**
     * Fetch the consolidated /platform-billing-summary, which carries the
     * account-level purchased-vs-consumed + upcoming-invoice picture that
     * /platform-usage alone can't give.
     *
     * AUTH CAVEAT: the deployed platform-billing-summary authenticates the
     * account *owner's Supabase user JWT*, NOT the sk_live_ platform key we
     * hold. Called with the key it returns 401/403. We therefore treat an
     * auth failure as "summary not available over this surface" and degrade to
     * the platform-usage aggregate (which IS key-authed). If/when the endpoint
     * gains key-auth, this lights up automatically with no further changes.
     *
     * @return array{available:bool,reason:?string,body:array}
     */
    private function fetchBillingSummary(): array
    {
        try {
            /** @var \WHMCS\Module\Server\Swarmz\Api $api */
            $api = new \WHMCS\Module\Server\Swarmz\Api($this->apiKey, $this->baseUrl);
            $res = $api->billingSummary();
            $body = (isset($res['body']) && is_array($res['body'])) ? $res['body'] : [];
            return ['available' => true, 'reason' => null, 'body' => $body];
        } catch (\WHMCS\Module\Server\Swarmz\SwarmzApiException $e) {
            // 401/403 is the EXPECTED outcome with key auth — degrade quietly.
            $code = $e->getStatusCode();
            $reason = ($code === 401 || $code === 403) ? 'key_auth_unsupported' : ('http_' . $code);
            return ['available' => false, 'reason' => $reason, 'body' => []];
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'unavailable', 'body' => []];
        }
    }

    // ------------------------------------------------------------- render

    private function renderToolbar(string $period): string
    {
        $periods = [
            'current_month' => 'This month',
            'last_month'    => 'Last month',
            'ytd'           => 'Year to date',
        ];
        $btns = '';
        foreach ($periods as $key => $label) {
            $active = $key === $period ? ' swz-tab-active' : '';
            $btns .= '<a class="swz-tab' . $active . '" href="' . $this->esc($this->link(['period' => $key])) . '">' . $this->esc($label) . '</a>';
        }
        $refresh = '<a class="swz-btn" href="' . $this->esc($this->link(['period' => $period])) . '">&#x21bb; Refresh</a>';
        $plans = '<a class="swz-btn" href="' . $this->esc($this->link(['swarmz_action' => 'plans'])) . '">Plans</a>';
        $promptBox = '<a class="swz-btn" href="' . $this->esc($this->link(['swarmz_action' => 'promptbox'])) . '">Prompt Box</a>';
        $test = '<a class="swz-btn" href="' . $this->esc($this->link(['period' => $period, 'swarmz_action' => 'testconn'])) . '">Test connection</a>';

        return '<div class="swz-toolbar"><div class="swz-tabs">' . $btns . '</div><div class="swz-actions">' . $plans . $promptBox . $refresh . $test . '</div></div>';
    }

    private function renderTestConnection(string $period): string
    {
        try {
            $this->fetchUsage($period);
            return $this->notice('success', 'Connection OK &mdash; your API key is valid and the Swarmz API is reachable.');
        } catch (\Throwable $e) {
            return $this->notice('danger', 'Connection failed: <code>' . $this->esc($this->scrub($e->getMessage())) . '</code>');
        }
    }

    /**
     * Render the "Plans" view — the account's named plans (from the new
     * key-authed platform-plans endpoint) as a table of their entitlements, so
     * the host can see at a glance what each plan grants and which `code` to put
     * in a product's "Plan" config option.
     *
     * Reuses the provisioning module's Api::listPlans(). Degrades gracefully:
     * an unreachable / undeployed endpoint, or zero plans, renders a tidy note
     * instead of an error (mirrors the dashboard's tolerance).
     */
    private function renderPlans(): string
    {
        $back = '<div class="swz-toolbar"><div class="swz-tabs"></div><div class="swz-actions">'
            . '<a class="swz-btn" href="' . $this->esc($this->link([])) . '">&larr; Back to dashboard</a>'
            . '</div></div>';
        $title = '<h3 class="swz-section-title">Named plans</h3>';
        $intro = '<p class="swz-muted" style="margin:0 0 10px;">Each plan bundles a full set of '
            . 'entitlements behind a stable <code>code</code>. Select a plan in a '
            . 'product&rsquo;s <strong>&ldquo;Plan&rdquo;</strong> module config option to provision by name '
            . '&mdash; the entitlements are resolved server-side from the plan.</p>';

        $plans = [];
        try {
            /** @var \WHMCS\Module\Server\Swarmz\Api $api */
            $api = new \WHMCS\Module\Server\Swarmz\Api($this->apiKey, $this->baseUrl);
            $plans = $api->listPlans();
        } catch (\WHMCS\Module\Server\Swarmz\SwarmzApiException $e) {
            $code = $e->getStatusCode();
            $why = ($code === 404)
                ? 'the <code>platform-plans</code> endpoint isn&rsquo;t deployed on your Swarmz API yet'
                : ('the API returned <code>' . $this->esc($this->scrub($e->getMessage())) . '</code>');
            return $back . $title . $this->notice('warning',
                'No named plans could be loaded &mdash; ' . $why . '. Provisioning requires a '
                . 'plan, so define your plans in the Swarmz admin area and ensure the endpoint '
                . 'is deployed, then select one on each product&rsquo;s Module Settings tab.'
            );
        } catch (\Throwable $e) {
            return $back . $title . $this->notice('danger',
                'Could not load plans: <code>' . $this->esc($this->scrub($e->getMessage())) . '</code>'
            );
        }

        if (empty($plans)) {
            return $back . $title . $intro . $this->notice('info',
                'Your account has no named plans defined yet. Define them in your Swarmz admin area '
                . '&mdash; a plan must be selected on each product before it can provision.'
            );
        }

        $rows = '';
        foreach ($plans as $p) {
            if (!is_array($p)) {
                continue;
            }
            $rows .= '<tr>'
                . '<td>' . $this->esc((string) ($p['display_name'] ?? ($p['code'] ?? '—'))) . '</td>'
                . '<td><code>' . $this->esc((string) ($p['code'] ?? '')) . '</code></td>'
                . '<td class="swz-num">' . $this->planNum($p['monthly_credits'] ?? null) . '</td>'
                . '<td class="swz-num">' . $this->planFreeGrant($p) . '</td>'
                . '<td class="swz-num">' . $this->planLaneGrant($p, 'monthly_cloud_credits', 'cloud_credit_mode') . '</td>'
                . '<td class="swz-num">' . $this->planLaneGrant($p, 'monthly_ai_credits', 'ai_credit_mode') . '</td>'
                . '<td class="swz-num">' . $this->planNum($p['monthly_credit_cap'] ?? null) . '</td>'
                . '<td class="swz-num">' . $this->planNum($p['rollover_months'] ?? null) . '</td>'
                . '<td class="swz-num">' . $this->planLimit($p['max_projects'] ?? null) . '</td>'
                . '<td class="swz-num">' . $this->planLimit($p['max_published_projects'] ?? null) . '</td>'
                . '<td class="swz-num">' . $this->planDomains($p) . '</td>'
                . '<td>' . $this->esc((string) ($p['max_compute_size'] ?? '—')) . '</td>'
                . '<td class="swz-num">' . $this->planCloudCap($p['cloud_budget_cap'] ?? null) . '</td>'
                . '<td class="swz-num">' . $this->planPrice($p) . '</td>'
                . '</tr>';
        }

        $table = '<div class="swz-tablewrap"><table class="swz-table">'
            . '<thead><tr>'
            . '<th>Plan</th><th>Code</th>'
            . '<th class="swz-num">Monthly credits</th><th class="swz-num">Free</th>'
            . '<th class="swz-num">Cloud</th><th class="swz-num">AI</th>'
            . '<th class="swz-num">Monthly cap</th><th class="swz-num">Rollover</th>'
            . '<th class="swz-num">Projects</th><th class="swz-num">Published</th>'
            . '<th class="swz-num">Domains</th><th>Compute</th>'
            . '<th class="swz-num">Cloud cap</th><th class="swz-num">Price</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="swz-muted" style="margin-top:10px;">Limits: &ldquo;&infin;&rdquo; = unlimited. '
            . 'Price is your wholesale plan price from Swarmz; set your retail price in WHMCS product pricing.</p>';

        return $back . $title . $intro . $table;
    }

    /** Format a numeric plan field; null/blank → "—". */
    /**
     * "Prompt Box" view — everything a host needs to put a prompt box on
     * their storefront: a snippet builder (live-updating embed code + inline
     * preview), how-it-works copy, and the recently captured prompts with
     * their journey (captured → in cart → provisioned).
     */
    private function renderPromptBox(): string
    {
        $back = '<div class="swz-toolbar"><div class="swz-tabs"></div><div class="swz-actions">'
            . '<a class="swz-btn" href="' . $this->esc($this->link([])) . '">&larr; Back to dashboard</a>'
            . '</div></div>';

        $systemUrl = rtrim(PromptBox::systemUrl(), '/');
        $jsUrl = $systemUrl . '/modules/addons/swarmz/promptbox.php?a=js';

        $out = $back;
        $out .= '<h3 class="swz-section-title">Prompt Box &mdash; capture the first prompt on YOUR site</h3>';
        $out .= '<p class="swz-muted" style="margin:0 0 14px;max-width:760px;">Paste one <code>&lt;script&gt;</code> tag '
            . 'on any page (plain HTML, WordPress, any builder). Visitors type the app they want, pick a plan, and land '
            . 'in your WHMCS cart &mdash; the prompt rides along automatically. When the order provisions, their workspace '
            . 'opens on their very first login with that app <strong>already building</strong>.</p>';

        $products = PromptBox::swarmzProducts();
        $visible = array_values(array_filter($products, function ($p) {
            return empty($p['hidden']);
        }));
        if (empty($products)) {
            $out .= $this->notice('warning',
                'No products use the Swarmz provisioning module yet. Create a product (Products/Services), set its '
                . 'Module to <strong>Swarmz</strong> and pick a plan, then come back here to generate your embed code.'
            );
            return $out;
        }
        $pool = !empty($visible) ? $visible : $products;

        // ---- Snippet builder (pure client-side JS; nothing stored) ----
        $productOptions = '';
        foreach ($pool as $p) {
            $productOptions .= '<option value="' . (int) $p['pid'] . '">'
                . $this->esc($p['name']) . ' [#' . (int) $p['pid'] . ']</option>';
        }

        $out .= '<div class="swz-cards" style="grid-template-columns:1fr;max-width:860px;">';
        $out .= '<div class="swz-card">';
        $out .= '<div class="swz-strong" style="margin-bottom:10px;">1 &middot; Configure</div>';
        $out .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;">'
            . '<label style="font-size:12px;color:#6b7280;">Product<br>'
            . '<select id="swzpb-pid" style="width:100%;margin-top:4px;padding:6px;border:1px solid #d1d5db;border-radius:6px;">'
            . $productOptions . '</select></label>'
            . '<label style="font-size:12px;color:#6b7280;">Button label<br>'
            . '<input id="swzpb-button" type="text" value="Start building" style="width:100%;margin-top:4px;padding:6px;border:1px solid #d1d5db;border-radius:6px;"></label>'
            . '<label style="font-size:12px;color:#6b7280;">Placeholder<br>'
            . '<input id="swzpb-placeholder" type="text" value="Describe the app you want to build&hellip;" style="width:100%;margin-top:4px;padding:6px;border:1px solid #d1d5db;border-radius:6px;"></label>'
            . '<label style="font-size:12px;color:#6b7280;">Theme<br>'
            . '<select id="swzpb-theme" style="width:100%;margin-top:4px;padding:6px;border:1px solid #d1d5db;border-radius:6px;">'
            . '<option value="auto">Auto (match visitor)</option><option value="light">Light</option><option value="dark">Dark</option>'
            . '</select></label>'
            . '<label style="font-size:12px;color:#6b7280;">Accent color<br>'
            . '<input id="swzpb-accent" type="color" value="#4f46e5" style="width:100%;margin-top:4px;padding:2px;border:1px solid #d1d5db;border-radius:6px;height:34px;"></label>'
            . '</div>';
        $out .= '<p class="swz-muted" style="margin:10px 0 0;">Optional: offer several plans inline by adding a '
            . '<code>data-plans</code> attribute &mdash; see the snippet comment. Each entry maps a label (and display '
            . 'price) to one of your WHMCS product ids.</p>';
        $out .= '</div>';

        $out .= '<div class="swz-card">';
        $out .= '<div class="swz-strong" style="margin-bottom:10px;">2 &middot; Copy the embed code</div>';
        $out .= '<pre id="swzpb-snippet" style="background:#0f172a;color:#e2e8f0;padding:14px;border-radius:8px;font-size:12px;'
            . 'line-height:1.6;overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:0;"></pre>';
        $out .= '<div style="margin-top:10px;"><a class="swz-btn" href="#" id="swzpb-copy">Copy to clipboard</a> '
            . '<span id="swzpb-copied" class="swz-muted" style="display:none;">Copied &check;</span></div>';
        $out .= '</div>';

        $out .= '<div class="swz-card">';
        $out .= '<div class="swz-strong" style="margin-bottom:10px;">3 &middot; Preview</div>';
        $out .= '<p class="swz-muted" style="margin:0 0 10px;">Live &mdash; exactly what your visitors will see. '
            . 'Submitting here really adds to the cart.</p>';
        $out .= '<div id="swzpb-preview"></div>';
        $out .= '</div>';
        $out .= '</div>';

        // Builder JS: rebuild the snippet text + preview on any input change.
        $jsUrlJson = json_encode($jsUrl, JSON_UNESCAPED_SLASHES);
        $out .= '<script>(function(){'
            . 'var jsUrl=' . $jsUrlJson . ';'
            . 'function esc(s){return String(s).replace(/&/g,"&amp;").replace(/"/g,"&quot;").replace(/</g,"&lt;");}'
            . 'function read(){return {'
            . 'pid:document.getElementById("swzpb-pid").value,'
            . 'button:document.getElementById("swzpb-button").value,'
            . 'placeholder:document.getElementById("swzpb-placeholder").value,'
            . 'theme:document.getElementById("swzpb-theme").value,'
            . 'accent:document.getElementById("swzpb-accent").value};}'
            . 'function snippet(c){return "<!-- Prompt Box — paste where the box should appear. -->\n"'
            . '+"<!-- Multiple plans? add: data-plans=\'[{\\"pid\\":"+c.pid+",\\"label\\":\\"Starter\\",\\"price\\":\\"$9/mo\\"}]\' -->\n"'
            . '+"<script src=\""+jsUrl+"\"\n"'
            . '+"        data-pid=\""+c.pid+"\"\n"'
            . '+"        data-button=\""+esc(c.button)+"\"\n"'
            . '+"        data-placeholder=\""+esc(c.placeholder)+"\"\n"'
            . '+"        data-theme=\""+c.theme+"\"\n"'
            . '+"        data-accent=\""+c.accent+"\"\n"'
            . '+"        async><\/script>";}'
            . 'function render(){var c=read();'
            . 'document.getElementById("swzpb-snippet").textContent=snippet(c);'
            . 'var pv=document.getElementById("swzpb-preview");pv.innerHTML="";'
            . 'var s=document.createElement("script");s.src=jsUrl+"&_="+Date.now();'
            . 's.setAttribute("data-pid",c.pid);s.setAttribute("data-button",c.button);'
            . 's.setAttribute("data-placeholder",c.placeholder);s.setAttribute("data-theme",c.theme);'
            . 's.setAttribute("data-accent",c.accent);s.setAttribute("data-target","#swzpb-preview");s.async=true;'
            . 'pv.appendChild(s);}'
            . '["swzpb-pid","swzpb-button","swzpb-placeholder","swzpb-theme","swzpb-accent"].forEach(function(id){'
            . 'document.getElementById(id).addEventListener("change",render);'
            . 'document.getElementById(id).addEventListener("input",render);});'
            . 'document.getElementById("swzpb-copy").addEventListener("click",function(ev){ev.preventDefault();'
            . 'var t=document.getElementById("swzpb-snippet").textContent;'
            . 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t);}'
            . 'else{var ta=document.createElement("textarea");ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand("copy");document.body.removeChild(ta);}'
            . 'var ok=document.getElementById("swzpb-copied");ok.style.display="inline";setTimeout(function(){ok.style.display="none";},1600);});'
            . 'render();'
            . '})();</script>';

        // ---- Recently captured prompts ----
        $out .= '<h3 class="swz-section-title">Recently captured prompts</h3>';
        $intents = PromptBox::recentIntents(20);
        if (empty($intents)) {
            $out .= '<p class="swz-muted">None yet &mdash; embed the widget and the prompts your visitors submit will show up here.</p>';
        } else {
            $productNames = [];
            foreach ($products as $p) {
                $productNames[(int) $p['pid']] = $p['name'];
            }
            $rows = '';
            foreach ($intents as $it) {
                $prompt = (string) $it->prompt;
                if (function_exists('mb_substr')) {
                    $excerpt = mb_substr($prompt, 0, 90) . (mb_strlen($prompt) > 90 ? '…' : '');
                } else {
                    $excerpt = substr($prompt, 0, 90) . (strlen($prompt) > 90 ? '…' : '');
                }
                if (!empty($it->used_at)) {
                    $status = '<span class="swz-badge" style="background:#16a34a;">Provisioned</span>';
                } elseif (!empty($it->service_id)) {
                    $status = '<span class="swz-badge" style="background:#f59e0b;">Ordered</span>';
                } else {
                    $status = '<span class="swz-badge" style="background:#6b7280;">Captured</span>';
                }
                $service = !empty($it->service_id)
                    ? '<a href="clientsservices.php?id=' . (int) $it->service_id . '">#' . (int) $it->service_id . '</a>'
                    : '<span class="swz-muted">&mdash;</span>';
                $rows .= '<tr>'
                    . '<td>' . $this->esc((string) $it->created_at) . '</td>'
                    . '<td>' . $this->esc($productNames[(int) $it->pid] ?? ('#' . (int) $it->pid)) . '</td>'
                    . '<td>' . $this->esc($excerpt) . '</td>'
                    . '<td>' . $status . '</td>'
                    . '<td>' . $service . '</td>'
                    . '</tr>';
            }
            $out .= '<div class="swz-tablewrap"><table class="swz-table"><thead><tr>'
                . '<th>Captured</th><th>Product</th><th>Prompt</th><th>Status</th><th>Service</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
        }

        return $out;
    }

    private function planNum($v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        return is_numeric($v) ? number_format((float) $v) : $this->esc((string) $v);
    }

    /**
     * Free-credits column: amount + grant cadence per the plan's
     * free_credit_mode ("5/day", "15/mo", "15 once", "—"). Plans from an
     * older API (no mode field) fall back to the historical per-day amount.
     */
    private function planFreeGrant(array $p): string
    {
        $mode = isset($p['free_credit_mode']) && is_string($p['free_credit_mode'])
            ? $p['free_credit_mode'] : null;
        switch ($mode) {
            case 'none':
                return '—';
            case 'one_time':
                $v = $p['free_credits_one_time'] ?? null;
                return (is_numeric($v) && (float) $v > 0) ? $this->planNum($v) . ' once' : '—';
            case 'monthly':
                $v = $p['free_credits_monthly'] ?? null;
                return (is_numeric($v) && (float) $v > 0) ? $this->planNum($v) . '/mo' : '—';
            default: // 'daily', or an older API without mode fields
                $v = $p['free_credits_per_day'] ?? null;
                if ($v === null || $v === '') {
                    return '—';
                }
                return (is_numeric($v) && (float) $v > 0) ? $this->planNum($v) . '/day' : '—';
        }
    }

    /**
     * Cloud/AI lane column: grant amount + cadence ("20/cycle", "20 once",
     * "—"). Missing amount (older API) or mode 'none' → "—".
     */
    private function planLaneGrant(array $p, string $amountKey, string $modeKey): string
    {
        $amount = $p[$amountKey] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0) {
            return '—';
        }
        $mode = isset($p[$modeKey]) && is_string($p[$modeKey]) ? $p[$modeKey] : null;
        if ($mode === 'none') {
            return '—';
        }
        return $this->planNum($amount) . ($mode === 'one_time' ? ' once' : '/cycle');
    }

    /** Format a capped limit where null/0 = unlimited (sentinel D1). */
    private function planLimit($v): string
    {
        if ($v === null || $v === '' || (is_numeric($v) && (int) $v === 0)) {
            return '&infin;';
        }
        return is_numeric($v) ? number_format((float) $v) : $this->esc((string) $v);
    }

    /** Domains column: respects the custom_domains_enabled master switch. */
    private function planDomains(array $p): string
    {
        $enabled = array_key_exists('custom_domains_enabled', $p)
            ? (bool) $p['custom_domains_enabled']
            : true;
        if (!$enabled) {
            return 'off';
        }
        return $this->planLimit($p['max_custom_domains'] ?? null);
    }

    /** Cloud budget cap: null/blank → "none". */
    private function planCloudCap($v): string
    {
        if ($v === null || $v === '') {
            return 'none';
        }
        return is_numeric($v) ? $this->money((float) $v) : $this->esc((string) $v);
    }

    /** Plan price from price_cents + currency; 0/absent → "—". */
    private function planPrice(array $p): string
    {
        if (!isset($p['price_cents']) || !is_numeric($p['price_cents']) || (int) $p['price_cents'] <= 0) {
            return '—';
        }
        $cur = isset($p['currency']) ? (string) $p['currency'] : 'USD';
        return $this->moneyCur(((int) $p['price_cents']) / 100, $cur);
    }

    private function renderSummary(array $services, array $usage, string $period): string
    {
        $active = 0;
        foreach ($services as $s) {
            if (strtolower((string) $s->status) === 'active') {
                $active++;
            }
        }
        $t = $usage['totals'];
        // The one money figure: the host's real WHOLESALE cost (AI + cloud $).
        $wholesale = $t['ai'] + $t['cloud'];

        // Per-lane CREDITS, summed across every workspace that has a live
        // balance. Shown as used / granted per lane — never USD.
        $sum = $this->sumLaneCredits($usage);

        $cards = [
            ['Active workspaces', (string) $active, '#2563eb', false],
            ['Build credits', $this->summaryLane($sum['buildUsed'], $sum['buildTotal']), '#7c3aed', true],
            ['Cloud credits', $this->summaryLane($sum['cloudUsed'], $sum['cloudTotal']), '#0891b2', true],
            ['AI credits', $this->summaryLane($sum['aiUsed'], $sum['aiTotal']), '#ca8a04', true],
            ['Wholesale cost', $this->money($wholesale), '#16a34a', false],
        ];
        $html = '<div class="swz-cards">';
        foreach ($cards as $c) {
            // Lane cards carry inline markup (the muted "/ total"); others are escaped.
            $value = $c[3] ? $c[1] : $this->esc($c[1]);
            $html .= '<div class="swz-card"><div class="swz-card-v" style="color:' . $c[2] . ';">' . $value . '</div>'
                . '<div class="swz-card-l">' . $this->esc($c[0]) . '</div></div>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Sum per-lane credit used/granted across all workspaces that reported a
     * live balance in the platform-usage response. Grants only count when > 0
     * (a lane absent from a plan contributes nothing to the denominator).
     *
     * @param array{bal?:array<string,array>} $usage
     * @return array{buildUsed:float,buildTotal:float,cloudUsed:float,cloudTotal:float,aiUsed:float,aiTotal:float}
     */
    private function sumLaneCredits(array $usage): array
    {
        $out = [
            'buildUsed' => 0.0, 'buildTotal' => 0.0,
            'cloudUsed' => 0.0, 'cloudTotal' => 0.0,
            'aiUsed'    => 0.0, 'aiTotal'    => 0.0,
        ];
        $bal = (isset($usage['bal']) && is_array($usage['bal'])) ? $usage['bal'] : [];
        foreach ($bal as $lanes) {
            if (!is_array($lanes)) {
                continue;
            }
            foreach (['build', 'cloud', 'ai'] as $lane) {
                $total = $lanes[$lane . 'Total'] ?? null;
                if (is_numeric($total) && (float) $total > 0) {
                    $out[$lane . 'Total'] += (float) $total;
                    $out[$lane . 'Used'] += (float) ($lanes[$lane . 'Used'] ?? 0);
                }
            }
        }
        return $out;
    }

    /**
     * Format a summary lane card value as "used / granted" credits, or an
     * em-dash when no plan in the account grants that lane.
     */
    private function summaryLane(float $used, float $total): string
    {
        if ($total <= 0) {
            return '&mdash;';
        }
        return number_format($used) . ' <span style="color:#9ca3af;font-size:15px;">/ ' . number_format($total) . '</span>';
    }

    /**
     * Render the consolidated billing summary: credits purchased vs consumed,
     * rollover/balance, and cloud spend vs cap. Laid out as its own card row
     * beneath the headline cards.
     *
     * Two modes:
     *   - available  → render the real platform-billing-summary fields
     *     (usage.credits_used/usd_credits/cloud_usd + upcoming invoice).
     *   - degraded   → key-auth can't reach the owner-only summary; render what
     *     we CAN derive from platform-usage (consumed credits, cloud spend) and
     *     cloud-spend-vs-cap from the WHMCS-configured caps, plus a short note.
     *
     * @param array{available:bool,reason:?string,body:array} $billing
     * @param array $usage    platform-usage aggregate (always available)
     * @param array<int,\stdClass> $services
     */
    private function renderBillingSummary(array $billing, array $usage, array $services): string
    {
        $title = '<h3 class="swz-section-title">Billing summary</h3>';

        if ($billing['available'] && !empty($billing['body'])) {
            $b = $billing['body'];
            $bUsage = (isset($b['usage']) && is_array($b['usage'])) ? $b['usage'] : [];
            $consumedCredits = (float) ($bUsage['credits_used'] ?? 0);
            $consumedUsd = (float) ($bUsage['usd_credits'] ?? 0);
            $cloudUsd = (float) ($bUsage['cloud_usd'] ?? 0);

            // Upcoming invoice → the period's accruing wholesale charge.
            $upcoming = (isset($b['upcoming']) && is_array($b['upcoming'])) ? $b['upcoming'] : null;
            $purchasedLabel = '—';
            if ($upcoming !== null) {
                $cents = (float) ($upcoming['amount_due_cents'] ?? 0);
                $cur = strtoupper((string) ($upcoming['currency'] ?? 'USD'));
                $purchasedLabel = $this->moneyCur($cents / 100, $cur);
            }

            $cards = [
                ['Credits consumed', number_format($consumedCredits), '#7c3aed'],
                ['AI spend (consumed)', $this->money($consumedUsd), '#0891b2'],
                ['Cloud spend', $this->money($cloudUsd), '#ca8a04'],
                ['Upcoming invoice', $purchasedLabel, '#16a34a'],
            ];

            $html = $title . '<div class="swz-cards">';
            foreach ($cards as $c) {
                $html .= '<div class="swz-card"><div class="swz-card-v" style="color:' . $c[2] . ';">' . $this->esc($c[1]) . '</div>'
                    . '<div class="swz-card-l">' . $this->esc($c[0]) . '</div></div>';
            }
            $html .= '</div>';

            // Recent invoices, if present.
            $invoices = (isset($b['invoices']) && is_array($b['invoices'])) ? $b['invoices'] : [];
            if (!empty($invoices)) {
                $html .= $this->renderInvoices($invoices);
            }
            return $html;
        }

        // ── Degraded mode: derive from platform-usage + configured caps. ──────
        $totals = isset($usage['totals']) && is_array($usage['totals']) ? $usage['totals'] : [];
        $consumedCredits = (float) ($totals['credits'] ?? 0);
        $consumedUsd = (float) ($totals['ai'] ?? 0);
        $cloudUsd = (float) ($totals['cloud'] ?? 0);

        // Aggregate cloud cap across active services from the plan caps the
        // platform-usage API returned (per-workspace caps.cloud_budget_cap).
        $capInfo = $this->aggregateCloudCap($services, $usage);

        $cloudCard = $this->money($cloudUsd);
        if ($capInfo['cap'] > 0) {
            $cloudCard = $this->money($cloudUsd) . ' <span style="color:#9ca3af;font-size:15px;">/ ' . $this->money($capInfo['cap']) . ' cap</span>';
        }

        $cards = [
            ['Credits consumed', number_format($consumedCredits), '#7c3aed'],
            ['AI spend (consumed)', $this->money($consumedUsd), '#0891b2'],
            ['Cloud spend vs cap', $cloudCard, '#ca8a04'],
        ];

        $html = $title . '<div class="swz-cards">';
        foreach ($cards as $c) {
            $html .= '<div class="swz-card"><div class="swz-card-v" style="color:' . $c[2] . ';">' . $c[1] . '</div>'
                . '<div class="swz-card-l">' . $this->esc($c[0]) . '</div></div>';
        }
        $html .= '</div>';

        // Explain why purchased/rollover/upcoming aren't shown here.
        $note = 'Credits <strong>purchased</strong>, rollover balance and the upcoming invoice live on your '
            . '<strong>Swarmz account billing page</strong> (owner sign-in) &mdash; that summary is tied to your '
            . 'Swarmz owner login, not the reseller API key, so it can&rsquo;t be pulled into WHMCS. '
            . 'The figures above are your live <strong>consumption</strong> for the selected period; '
            . 'cloud spend is shown against the total cap configured on your active plans.';
        $html .= '<div class="swz-notice" style="background:#f8fafc;border:1px solid #e5e7eb;color:#475569;">' . $note . '</div>';

        return $html;
    }

    /**
     * Sum the cloud_budget_cap across the active swarmz services, drawn from the
     * plan caps the platform-usage API returned (caps.cloud_budget_cap, keyed by
     * tenant_id). Returns { cap:float, count:int } where cap is the total
     * ceiling (0 when none of the active plans set one).
     *
     * @param array<int,\stdClass> $services
     * @param array{caps:array<string,array>} $usage
     * @return array{cap:float,count:int}
     */
    private function aggregateCloudCap(array $services, array $usage): array
    {
        $cap = 0.0;
        $count = 0;
        $capsByTenant = (isset($usage['caps']) && is_array($usage['caps'])) ? $usage['caps'] : [];
        foreach ($services as $s) {
            if (strtolower((string) $s->status) !== 'active') {
                continue;
            }
            $tenant = (string) $s->tenantid;
            if ($tenant === '' || !isset($capsByTenant[$tenant]) || !is_array($capsByTenant[$tenant])) {
                continue;
            }
            $val = $capsByTenant[$tenant]['cloud_budget_cap'] ?? null;
            if ($val === null || $val === '' || !is_numeric($val)) {
                continue;
            }
            $f = (float) $val;
            if ($f > 0) {
                $cap += $f;
                $count++;
            }
        }
        return ['cap' => $cap, 'count' => $count];
    }

    /**
     * Render a compact recent-invoices table from platform-billing-summary's
     * `invoices` array.
     *
     * @param array<int,array> $invoices
     */
    private function renderInvoices(array $invoices): string
    {
        $rows = '';
        $shown = 0;
        foreach ($invoices as $inv) {
            if ($shown >= 6) {
                break;
            }
            $shown++;
            $status = (string) ($inv['status'] ?? '');
            $cur = strtoupper((string) ($inv['currency'] ?? 'USD'));
            $due = (float) ($inv['amount_due_cents'] ?? 0) / 100;
            $paid = (float) ($inv['amount_paid_cents'] ?? 0) / 100;
            $when = '';
            if (!empty($inv['created_at'])) {
                $when = substr((string) $inv['created_at'], 0, 10);
            }
            $link = '';
            if (!empty($inv['hosted_invoice_url'])) {
                $link = '<a href="' . $this->esc((string) $inv['hosted_invoice_url']) . '" target="_blank" rel="noopener">View</a>';
            }
            $rows .= '<tr>'
                . '<td>' . $this->esc($when) . '</td>'
                . '<td>' . $this->statusBadge($status) . '</td>'
                . '<td class="swz-num">' . $this->moneyCur($due, $cur) . '</td>'
                . '<td class="swz-num">' . $this->moneyCur($paid, $cur) . '</td>'
                . '<td>' . $link . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            return '';
        }
        return '<div class="swz-tablewrap" style="margin-top:8px;"><table class="swz-table">'
            . '<thead><tr><th>Date</th><th>Status</th><th class="swz-num">Due</th><th class="swz-num">Paid</th><th></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    private function renderTable(array $services, array $usage): string
    {
        if (empty($services)) {
            return $this->notice('info',
                'No provisioned Swarmz workspaces yet. Once a customer orders a product that uses the '
                . 'Swarmz provisioning module and it activates, it will appear here.'
            );
        }

        $map = $usage['map'];
        $bal = (isset($usage['bal']) && is_array($usage['bal'])) ? $usage['bal'] : [];
        $rows = '';
        foreach ($services as $s) {
            $tenant = (string) $s->tenantid;
            $u = isset($map[$tenant]) ? $map[$tenant] : ['credits' => 0, 'ai' => 0, 'cloud' => 0];
            // Wholesale = the host's real cost (AI $ + Cloud $ from platform-usage).
            $wholesale = $u['ai'] + $u['cloud'];
            $lanes = isset($bal[$tenant]) && is_array($bal[$tenant]) ? $bal[$tenant] : null;

            $name = trim((string) $s->firstname . ' ' . (string) $s->lastname);
            if ($name === '') {
                $name = (string) $s->companyname;
            }
            if ($name === '') {
                $name = 'Client #' . (int) $s->clientid;
            }

            $clientUrl = 'clientssummary.php?userid=' . (int) $s->clientid;
            $serviceUrl = 'clientsservices.php?userid=' . (int) $s->clientid . '&id=' . (int) $s->serviceid;
            $tenantShort = strlen($tenant) > 10 ? substr($tenant, 0, 8) . '…' : $tenant;

            $rows .= '<tr>'
                . '<td><a href="' . $this->esc($clientUrl) . '">' . $this->esc($name) . '</a>'
                . ($s->domain ? '<div class="swz-muted">' . $this->esc((string) $s->domain) . '</div>' : '') . '</td>'
                . '<td>' . $this->esc((string) ($s->productname ?: '—')) . '</td>'
                . '<td>' . $this->statusBadge((string) $s->status) . '</td>'
                . '<td><code title="' . $this->esc($tenant) . '">' . $this->esc($tenantShort) . '</code></td>'
                . '<td class="swz-num">' . $this->buildLaneCell($lanes) . '</td>'
                . '<td class="swz-num">' . $this->laneCell($lanes, 'cloud') . '</td>'
                . '<td class="swz-num">' . $this->laneCell($lanes, 'ai') . '</td>'
                . '<td class="swz-num swz-strong">' . $this->money($wholesale) . '</td>'
                . '<td><a class="swz-btn swz-btn-sm" href="' . $this->esc($serviceUrl) . '">Manage</a></td>'
                . '</tr>';
        }

        return '<div class="swz-tablewrap"><table class="swz-table">'
            . '<thead><tr>'
            . '<th>Customer</th><th>Plan</th><th>Status</th><th>Tenant</th>'
            . '<th class="swz-num">Build credits</th><th class="swz-num">Cloud credits</th>'
            . '<th class="swz-num">AI credits</th><th class="swz-num">Wholesale cost</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="swz-muted" style="margin-top:10px;">Tenant = Swarmz workspace id stored on the service. '
            . 'Credit lanes show <strong>used / grant</strong> this cycle (build includes any rollover + top-up '
            . 'still available). <strong>Wholesale cost</strong> is your AI + cloud cost from Swarmz for the '
            . 'selected period &mdash; set your retail price in WHMCS product pricing. A row of dashes simply had '
            . 'no live balance for that lane.</p>';
    }

    /**
     * Render the Build-lane cell: "used / grant" credits, with a muted sub-line
     * for rollover + top-up remaining when either is present. Falls back to "—"
     * when there is no live balance for this workspace.
     *
     * @param array<string,mixed>|null $lanes
     */
    private function buildLaneCell(?array $lanes): string
    {
        if ($lanes === null) {
            return '—';
        }
        $main = $this->laneUsedTotal((float) ($lanes['buildUsed'] ?? 0), $lanes['buildTotal'] ?? null);

        $extra = [];
        $roll = (float) ($lanes['rolloverRemaining'] ?? 0);
        if ($roll > 0) {
            $extra[] = '+' . number_format($roll) . ' rollover';
        }
        $topup = (float) ($lanes['topupRemaining'] ?? 0);
        if ($topup > 0) {
            $extra[] = '+' . number_format($topup) . ' top-up';
        }
        if (!empty($extra)) {
            $main .= '<div class="swz-muted">' . $this->esc(implode(' · ', $extra)) . ' left</div>';
        }
        return $main;
    }

    /**
     * Render a Cloud/AI lane cell as "used / grant" credits, or "—" when the
     * lane isn't granted on this plan (or there's no live balance).
     *
     * @param array<string,mixed>|null $lanes
     * @param string $lane 'cloud' | 'ai'
     */
    private function laneCell(?array $lanes, string $lane): string
    {
        if ($lanes === null) {
            return '—';
        }
        return $this->laneUsedTotal(
            (float) ($lanes[$lane . 'Used'] ?? 0),
            $lanes[$lane . 'Total'] ?? null
        );
    }

    /**
     * Format a "used / total" credits pair. A null/≤0 total renders "—" (the
     * lane isn't part of the plan), so we never imply a 0-credit grant exists.
     *
     * @param float      $used
     * @param float|null $total
     */
    private function laneUsedTotal(float $used, $total): string
    {
        if ($total === null || (float) $total <= 0) {
            return '—';
        }
        return number_format($used) . ' <span class="swz-muted">/ ' . number_format((float) $total) . '</span>';
    }

    // ------------------------------------------------------------- helpers

    private function link(array $params): string
    {
        $url = $this->modulelink;
        foreach ($params as $k => $v) {
            $url .= '&' . rawurlencode($k) . '=' . rawurlencode((string) $v);
        }
        return $url;
    }

    private function safePeriod(string $p): string
    {
        return in_array($p, ['current_month', 'last_month', 'ytd'], true) ? $p : 'current_month';
    }

    private function periodLabel(array $apiPeriod, string $fallback): string
    {
        $label = isset($apiPeriod['label']) ? (string) $apiPeriod['label'] : $fallback;
        $map = ['current_month' => 'this month', 'last_month' => 'last month', 'ytd' => 'YTD'];
        return isset($map[$label]) ? $map[$label] : $label;
    }

    private function statusBadge(string $status): string
    {
        $s = strtolower($status);
        $color = '#6b7280';
        if ($s === 'active') {
            $color = '#16a34a';
        } elseif ($s === 'suspended') {
            $color = '#d97706';
        } elseif ($s === 'terminated' || $s === 'cancelled') {
            $color = '#dc2626';
        } elseif ($s === 'pending') {
            $color = '#2563eb';
        }
        return '<span class="swz-badge" style="background:' . $color . ';">' . $this->esc($status ?: 'Unknown') . '</span>';
    }

    private function notice(string $type, string $html): string
    {
        $colors = [
            'info'    => ['#eff6ff', '#bfdbfe', '#1e40af'],
            'success' => ['#f0fdf4', '#bbf7d0', '#166534'],
            'warning' => ['#fffbeb', '#fde68a', '#92400e'],
            'danger'  => ['#fef2f2', '#fecaca', '#991b1b'],
        ];
        $c = isset($colors[$type]) ? $colors[$type] : $colors['info'];
        return '<div class="swz-notice" style="background:' . $c[0] . ';border:1px solid ' . $c[1] . ';color:' . $c[2] . ';">' . $html . '</div>';
    }

    private function money(float $n): string
    {
        return '$' . number_format($n, 2);
    }

    /**
     * Money with an explicit currency. USD renders with the $ prefix; any other
     * currency is suffixed with its ISO code (e.g. "12.00 EUR") so we never
     * imply USD for a non-USD figure.
     */
    private function moneyCur(float $n, string $currency): string
    {
        $cur = strtoupper(trim($currency));
        if ($cur === '' || $cur === 'USD') {
            return '$' . number_format($n, 2);
        }
        return number_format($n, 2) . ' ' . $this->esc($cur);
    }

    private function esc($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private function scrub(string $s): string
    {
        $out = preg_replace('/sk_(live|test)_[A-Za-z0-9_\-]+/', '[redacted]', $s);
        return $out === null ? '' : $out;
    }

    private function styles(): string
    {
        return '<style>
.swarmz-console{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#111827;}
.swarmz-console .swz-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:8px;}
.swarmz-console .swz-title{margin:0;font-size:22px;font-weight:700;}
.swarmz-console .swz-sub{margin:4px 0 0;color:#6b7280;font-size:13px;max-width:760px;}
.swarmz-console .swz-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:16px 0;}
.swarmz-console .swz-tabs{display:inline-flex;background:#f3f4f6;border-radius:8px;padding:3px;}
.swarmz-console .swz-tab{padding:6px 14px;border-radius:6px;font-size:13px;color:#374151;text-decoration:none;}
.swarmz-console .swz-tab-active{background:#fff;color:#111827;font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.08);}
.swarmz-console .swz-actions{display:inline-flex;gap:8px;}
.swarmz-console .swz-btn{display:inline-block;padding:6px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;color:#374151;text-decoration:none;font-size:13px;}
.swarmz-console .swz-btn:hover{background:#f9fafb;}
.swarmz-console .swz-btn-sm{padding:3px 10px;font-size:12px;}
.swarmz-console .swz-section-title{margin:18px 0 8px;font-size:15px;font-weight:700;color:#374151;}
.swarmz-console .swz-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:8px 0 20px;}
.swarmz-console .swz-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;}
.swarmz-console .swz-card-v{font-size:26px;font-weight:700;line-height:1.1;}
.swarmz-console .swz-card-l{margin-top:6px;color:#6b7280;font-size:12px;}
.swarmz-console .swz-tablewrap{overflow-x:auto;border:1px solid #e5e7eb;border-radius:10px;}
.swarmz-console .swz-table{width:100%;border-collapse:collapse;font-size:13px;}
.swarmz-console .swz-table th,.swarmz-console .swz-table td{padding:10px 12px;border-bottom:1px solid #f0f1f3;text-align:left;vertical-align:top;}
.swarmz-console .swz-table thead th{background:#f9fafb;color:#6b7280;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.03em;}
.swarmz-console .swz-table tbody tr:last-child td{border-bottom:none;}
.swarmz-console .swz-num{text-align:right;font-variant-numeric:tabular-nums;}
.swarmz-console .swz-strong{font-weight:700;}
.swarmz-console .swz-muted{color:#9ca3af;font-size:12px;}
.swarmz-console .swz-badge{display:inline-block;padding:2px 8px;border-radius:999px;color:#fff;font-size:11px;font-weight:600;}
.swarmz-console .swz-notice{padding:12px 14px;border-radius:8px;margin:12px 0;font-size:13px;}
.swarmz-console code{background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:12px;}
</style>';
    }
}
