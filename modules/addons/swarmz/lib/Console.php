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
            . '<h2 class="swz-title">Swarmz Reseller Console</h2>'
            . '<p class="swz-sub">Your customers, what they&rsquo;re using, and what it costs you.</p>'
            . '</div>';

        if (!$this->serverLibAvailable) {
            $srvDir = __DIR__ . '/../../../servers/swarmz';
            if (is_dir($srvDir) && !is_file($srvDir . '/lib/Api.php')) {
                // The folder is THERE but PHP cannot read into it (or the
                // upload is partial). Almost always ownership/permissions
                // after manual server surgery — say so instead of the
                // misleading "not found".
                return $out . $this->notice('warning',
                    '<strong>The provisioning module exists but can&rsquo;t be read.</strong> '
                    . '<code>modules/servers/swarmz/</code> is present, yet PHP cannot read '
                    . '<code>lib/Api.php</code> inside it. That is almost always file ownership or '
                    . 'permissions: everything under the module folders must be owned by the user PHP runs as, '
                    . 'directories <code>755</code>, files <code>644</code> &mdash; never world-writable. '
                    . 'Run <code>namei -l &hellip;/modules/servers/swarmz/lib/Api.php</code>, fix the first entry '
                    . 'that denies read, then reload. (A partial upload can also cause this &mdash; re-upload the release ZIP.)'
                ) . '</div>';
            }
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

        // Admin SSO launcher (v1.11.0) — "Open workspace" from the admin
        // service tab. Mints a fresh platform-sso redirect server-side and
        // 302s in a new tab, mirroring the client area's `launch` action
        // (which admins can't use: sso.php?direct requires a CLIENT session,
        // and AdminLink only renders on the Servers config page). GET-safe:
        // minting a short-lived redirect is idempotent and mutates nothing.
        // On success renderAdminSso() redirects + exits; returning here means
        // failure, so render the error inside the console shell.
        if ($action === 'adminsso') {
            return $out . $this->renderAdminSso() . '</div>';
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

        // Dedicated "Update" view — in-admin module updater (v1.14.0):
        // version check against the pinned GitHub repo, preflight, and a
        // CSRF'd one-click install. See lib/Updater.php for the fail-closed
        // security model.
        if ($action === 'update') {
            $out .= $this->renderUpdatePage();
            $out .= '</div>';
            return $out;
        }

        // Dedicated "Credit Packs" view — map WHMCS Product Addons to the
        // Swarmz top-up credits they grant when paid. Fully local (mapping
        // table + tbladdons); the grants themselves ride the InvoicePaid hook.
        if ($action === 'creditpacks') {
            $out .= $this->renderCreditPacks();
            $out .= '</div>';
            return $out;
        }

        // Dedicated "Appearance" view — the customer-panel theme, color
        // scheme, and accent. Stored in tbladdonmodules (same rows the old
        // module-settings fields used), so existing choices carry over.
        if ($action === 'appearance') {
            $out .= $this->renderAppearance();
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
        $out .= $this->renderUpdateBanner();
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
        $packs = '<a class="swz-btn" href="' . $this->esc($this->link(['swarmz_action' => 'creditpacks'])) . '">Credit Packs</a>';
        $appearance = '<a class="swz-btn" href="' . $this->esc($this->link(['swarmz_action' => 'appearance'])) . '">Appearance</a>';
        $promptBox = '<a class="swz-btn" href="' . $this->esc($this->link(['swarmz_action' => 'promptbox'])) . '">Prompt Box</a>';
        $updates = '<a class="swz-btn" href="' . $this->esc($this->link(['swarmz_action' => 'update'])) . '">Updates</a>';
        $test = '<a class="swz-btn" href="' . $this->esc($this->link(['period' => $period, 'swarmz_action' => 'testconn'])) . '">Test connection</a>';

        return '<div class="swz-toolbar"><div class="swz-tabs">' . $btns . '</div><div class="swz-actions">' . $plans . $packs . $appearance . $promptBox . $updates . $refresh . $test . '</div></div>';
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
        $intro = '<p class="swz-lede">Each plan bundles a full set of '
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
        $out .= '<h3 class="swz-section-title">Prompt Box &mdash; capture the first prompt on your site</h3>';
        $out .= '<p class="swz-lede">Paste one <code>&lt;script&gt;</code> tag '
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
        $out .= '<pre id="swzpb-snippet" style="background:#111827;color:#e5e7eb;padding:14px;border-radius:8px;font-size:12px;'
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
                    $status = '<span class="swz-badge swz-badge-ok">Provisioned</span>';
                } elseif (!empty($it->service_id)) {
                    $status = '<span class="swz-badge swz-badge-warn">Ordered</span>';
                } else {
                    $status = '<span class="swz-badge swz-badge-neutral">Captured</span>';
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

    /**
     * "Credit usage · current cycle" section: active-workspace count plus the
     * three credit lanes (Build / Cloud / AI), each read as "used of granted".
     *
     * IMPORTANT SEMANTICS: the lane figures come from the platform-usage
     * response's `balances` section — a LIVE snapshot of the current billing
     * cycle. The period tabs scope the USD *cost* figures (wholesale section
     * below), not these balances; the section caption says so explicitly.
     * The one money figure (wholesale cost) now lives in renderBillingSummary.
     */
    private function renderSummary(array $services, array $usage, string $period): string
    {
        $active = 0;
        foreach ($services as $s) {
            if (strtolower((string) $s->status) === 'active') {
                $active++;
            }
        }

        // Per-lane CREDITS, summed across every workspace that has a live
        // balance. Shown as "used of granted" per lane — never USD.
        $sum = $this->sumLaneCredits($usage);

        return '<div class="swz-section">'
            . '<h3 class="swz-section-title">Balances right now</h3>'
            . '<p class="swz-section-sub">All customer workspaces together, this billing cycle &mdash; each number reads used of granted. The tabs above change the cost figures below, not these.</p>'
            . '<div class="swz-cards">'
            . $this->statCard('Active workspaces', $this->esc((string) $active),
                'Active services in WHMCS.')
            . $this->statCard('Build credits', $this->summaryLane($sum['buildUsed'], $sum['buildTotal']),
                'Used of currently assigned, this cycle.')
            . $this->statCard('Top-up credits', $sum['topup'] > 0 ? $this->esc(number_format($sum['topup'])) : '&mdash;',
                'Purchased credits your customers still hold.')
            . $this->statCard('Cloud credits', $this->summaryLane($sum['cloudUsed'], $sum['cloudTotal']),
                'Used of the plans&rsquo; cloud grant.')
            . $this->statCard('AI credits', $this->summaryLane($sum['aiUsed'], $sum['aiTotal']),
                'Used of the plans&rsquo; AI grant.')
            . '</div></div>';
    }

    /**
     * One monochrome stat tile: small uppercase label, ink value, muted
     * caption. $valueHtml and $captionHtml are trusted HTML (callers escape
     * any dynamic text); $label is escaped here.
     */
    private function statCard(string $label, string $valueHtml, string $captionHtml = ''): string
    {
        return '<div class="swz-card"><div class="swz-card-l">' . $this->esc($label) . '</div>'
            . '<div class="swz-card-v">' . $valueHtml . '</div>'
            . ($captionHtml !== '' ? '<div class="swz-card-c">' . $captionHtml . '</div>' : '')
            . '</div>';
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
            'topup'     => 0.0,
        ];
        $bal = (isset($usage['bal']) && is_array($usage['bal'])) ? $usage['bal'] : [];
        foreach ($bal as $lanes) {
            if (!is_array($lanes)) {
                continue;
            }
            $out['topup'] += (float) ($lanes['topupRemaining'] ?? 0);
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
     * Format a summary lane card value as "used of granted" credits, or an
     * em-dash when no plan in the account grants that lane.
     */
    private function summaryLane(float $used, float $total): string
    {
        if ($total <= 0) {
            return '&mdash;';
        }
        return number_format($used) . ' <span class="swz-of">of ' . number_format($total) . '</span>';
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
        $periodLabel = $this->periodLabel(
            (isset($usage['period']) && is_array($usage['period'])) ? $usage['period'] : [],
            'this period'
        );
        $head = '<h3 class="swz-section-title">Your cost &middot; ' . $this->esc($periodLabel) . '</h3>'
            . '<p class="swz-section-sub">What Swarmz charges you. Your own retail prices live in WHMCS.</p>';

        if ($billing['available'] && !empty($billing['body'])) {
            $b = $billing['body'];
            $bUsage = (isset($b['usage']) && is_array($b['usage'])) ? $b['usage'] : [];
            $consumedCredits = (float) ($bUsage['credits_used'] ?? 0);
            $consumedUsd = (float) ($bUsage['usd_credits'] ?? 0);
            $cloudUsd = (float) ($bUsage['cloud_usd'] ?? 0);

            // Upcoming invoice → the period's accruing wholesale charge.
            $upcoming = (isset($b['upcoming']) && is_array($b['upcoming'])) ? $b['upcoming'] : null;
            $upcomingLabel = '&mdash;';
            if ($upcoming !== null) {
                $cents = (float) ($upcoming['amount_due_cents'] ?? 0);
                $cur = strtoupper((string) ($upcoming['currency'] ?? 'USD'));
                $upcomingLabel = $this->esc($this->moneyCur($cents / 100, $cur));
            }

            $html = '<div class="swz-section">' . $head . '<div class="swz-cards">'
                . $this->statCard('Wholesale total', $this->esc($this->money($consumedUsd + $cloudUsd)),
                    'AI + cloud together.')
                . $this->statCard('AI spend', $this->esc($this->money($consumedUsd)),
                    'Spent on AI.')
                . $this->statCard('Cloud spend', $this->esc($this->money($cloudUsd)),
                    'Spent on cloud compute.')
                . $this->statCard('Upcoming invoice', $upcomingLabel,
                    'Your next Swarmz invoice, so far.')
                . $this->statCard('Credits consumed', $this->esc(number_format($consumedCredits)),
                    'Build credits used in this period.')
                . '</div>';

            // Recent invoices, if present.
            $invoices = (isset($b['invoices']) && is_array($b['invoices'])) ? $b['invoices'] : [];
            if (!empty($invoices)) {
                $html .= $this->renderInvoices($invoices);
            }
            return $html . '</div>';
        }

        // ── Degraded mode: derive from platform-usage + configured caps. ──────
        $totals = isset($usage['totals']) && is_array($usage['totals']) ? $usage['totals'] : [];
        $consumedCredits = (float) ($totals['credits'] ?? 0);
        $consumedUsd = (float) ($totals['ai'] ?? 0);
        $cloudUsd = (float) ($totals['cloud'] ?? 0);

        // Aggregate cloud cap across active services from the plan caps the
        // platform-usage API returned (per-workspace caps.cloud_budget_cap).
        $capInfo = $this->aggregateCloudCap($services, $usage);

        $cloudValue = $this->esc($this->money($cloudUsd));
        $cloudCaption = 'Spent on cloud compute.';
        if ($capInfo['cap'] > 0) {
            $cloudValue .= ' <span class="swz-of">of ' . $this->esc($this->money($capInfo['cap'])) . ' cap</span>';
            $cloudCaption = 'Spent on cloud compute, against your plans&rsquo; total cap.';
        }

        $html = '<div class="swz-section">' . $head . '<div class="swz-cards">'
            . $this->statCard('Wholesale total', $this->esc($this->money($consumedUsd + $cloudUsd)),
                'AI + cloud together.')
            . $this->statCard('AI spend', $this->esc($this->money($consumedUsd)),
                'Spent on AI.')
            . $this->statCard('Cloud spend', $cloudValue, $cloudCaption)
            . $this->statCard('Credits consumed', $this->esc(number_format($consumedCredits)),
                'Build credits used in this period.')
            . '</div>';

        // Compact footnote: why purchased/rollover/upcoming aren't shown here.
        $html .= '<p class="swz-note">Your own purchases, rollover and the upcoming invoice need your owner sign-in &mdash; find them on the Swarmz billing page.</p>';

        return $html . '</div>';
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

        return '<div class="swz-section">'
            . '<h3 class="swz-section-title">Customers</h3>'
            . '<p class="swz-section-sub">Per customer, this cycle &mdash; used / included. The build column also shows rollover and top-ups still available; a dash means no live balance.</p>'
            . '<div class="swz-tablewrap"><table class="swz-table">'
            . '<thead><tr>'
            . '<th>Customer</th><th>Plan</th><th>Status</th><th>Tenant</th>'
            . '<th class="swz-num">Build credits</th><th class="swz-num">Cloud credits</th>'
            . '<th class="swz-num">AI credits</th><th class="swz-num">Wholesale cost</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="swz-muted" style="margin-top:8px;">Tenant is the Swarmz workspace id stored on the WHMCS service. '
            . 'Wholesale cost is your AI + cloud cost from Swarmz for the selected period &mdash; set your retail price '
            . 'in WHMCS product pricing.</p>'
            . '</div>';
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

    /**
     * Semantic status pill — muted tinted background + dark text (never a
     * saturated fill). Also used for invoice statuses (paid/open).
     */
    private function statusBadge(string $status): string
    {
        $s = strtolower($status);
        $class = 'swz-badge-neutral';
        if ($s === 'active' || $s === 'paid') {
            $class = 'swz-badge-ok';
        } elseif ($s === 'suspended') {
            $class = 'swz-badge-warn';
        } elseif ($s === 'terminated' || $s === 'cancelled') {
            $class = 'swz-badge-bad';
        } elseif ($s === 'pending' || $s === 'open') {
            $class = 'swz-badge-info';
        }
        return '<span class="swz-badge ' . $class . '">' . $this->esc($status ?: 'Unknown') . '</span>';
    }

    /**
     * One-line banner on the dashboard when a newer release exists. Cached
     * (6 h TTL) — a failed or rate-limited check renders nothing rather than
     * an error, so the host's admin is never degraded by our release feed.
     */
    private function renderUpdateBanner(): string
    {
        try {
            $info = Updater::check();
            if (!Updater::updateAvailable($info)) {
                return '';
            }
            return $this->notice('info',
                '<strong>Module update available:</strong> v' . $this->esc(Updater::currentVersion())
                . ' &rarr; v' . $this->esc((string) $info['version'])
                . ' &nbsp; <a class="swz-btn" href="' . $this->esc($this->link(['swarmz_action' => 'update'])) . '">Review &amp; update</a>'
            );
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * "Update" page: current vs latest, release notes, environment preflight,
     * and the one-click install (POST + WHMCS admin token). GET never mutates;
     * "Check again" forces a fresh version check.
     */
    private function renderUpdatePage(): string
    {
        $back = '<div class="swz-toolbar"><div class="swz-tabs"></div><div class="swz-actions">'
            . '<a class="swz-btn" href="' . $this->esc($this->link([])) . '">&larr; Back to dashboard</a>'
            . '<a class="swz-btn" href="' . $this->esc($this->link(['swarmz_action' => 'update', 'recheck' => '1'])) . '">Check again</a>'
            . '</div></div>';
        $title = '<h3 class="swz-section-title">Module updates</h3>';
        $out = $back . $title;

        // Explicit, admin-clicked install — never automatic.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['swz_do_update'])) {
            if (function_exists('check_token')) {
                check_token('WHMCS.admin.default');
            }
            $result = Updater::performUpdate(isset($_POST['swz_confirm_overwrite']));
            if (!empty($result['ok'])) {
                return $out . $this->notice('success',
                    '<strong>Updated to v' . $this->esc($result['to']) . '.</strong> '
                    . (int) $result['files'] . ' files installed; the previous version was backed up to '
                    . '<code>' . $this->esc(str_replace($this->whmcsRootForDisplay(), '', (string) $result['backup'])) . '</code>. '
                    . 'Open <strong>System Settings &rarr; Addon Modules</strong> once so WHMCS runs the version upgrade, then reload this console.'
                    . (!empty($result['note']) ? '<br><span class="swz-muted">' . $this->esc((string) $result['note']) . '</span>' : '')
                );
            }
            $out .= $this->notice('danger', '<strong>Update not applied:</strong> ' . $this->esc((string) ($result['error'] ?? 'unknown error')));
        }

        $force = isset($_REQUEST['recheck']);
        $info = Updater::check($force);
        $current = Updater::currentVersion();

        if (empty($info['ok'])) {
            return $out . $this->notice('warning',
                'Could not reach the release feed just now (' . $this->esc((string) ($info['error'] ?? 'unknown')) . '). '
                . 'You are on v' . $this->esc($current) . '; try again in a few minutes.'
            );
        }

        if (!Updater::updateAvailable($info)) {
            $out .= $this->notice('success', 'You are up to date &mdash; v' . $this->esc($current) . ' is the latest release.');
            return $out;
        }

        $out .= $this->notice('info',
            '<strong>v' . $this->esc((string) $info['version']) . ' is available</strong> (you are on v' . $this->esc($current) . ').'
        );

        if (!empty($info['notes'])) {
            $out .= '<div class="swz-tablewrap" style="padding:16px 18px;font-size:12.5px;line-height:1.6;max-height:340px;overflow:auto;">'
                . $this->mdNotes((string) $info['notes']) . '</div>';
        }

        // Preflight — every row must be green before the button does anything.
        $rows = '';
        $allOk = true;
        foreach (Updater::preflight() as $c) {
            $allOk = $allOk && $c['ok'];
            $badge = $c['ok']
                ? '<span class="swz-badge swz-badge-ok">OK</span>'
                : '<span class="swz-badge swz-badge-warn">Blocked</span>';
            $rows .= '<tr><td>' . $this->esc($c['label']) . '</td><td>' . $badge . '</td>'
                . '<td class="swz-muted">' . $this->esc($c['detail']) . '</td></tr>';
        }
        $out .= '<div class="swz-tablewrap"><table class="swz-table">'
            . '<thead><tr><th>Check</th><th></th><th>Detail</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';

        if (!$allOk) {
            $out .= $this->notice('warning',
                'One or more checks are blocked, so the in-admin update is disabled. You can always update by '
                . 'uploading the release ZIP over the WHMCS root instead &mdash; same result, same safety.'
            );
            return $out;
        }

        // Hand-modification guard (v1.15.0): list files this install has
        // changed relative to what its release shipped; overwriting them
        // requires the explicit checkbox below (enforced server-side too).
        $local = Updater::detectLocalModifications();
        $touched = array_merge($local['modified'], $local['missing']);
        $needsConfirm = !$local['manifest'] || !empty($touched);
        if ($local['manifest'] && empty($touched)) {
            $out .= $this->notice('success', 'No local modifications detected &mdash; every module file matches the installed release, so updating is safe.');
        } elseif ($local['manifest']) {
            $rows = '';
            foreach ($local['modified'] as $f) {
                $rows .= '<tr><td><code>' . $this->esc($f) . '</code></td><td><span class="swz-badge swz-badge-warn">Modified locally</span></td></tr>';
            }
            foreach ($local['missing'] as $f) {
                $rows .= '<tr><td><code>' . $this->esc($f) . '</code></td><td><span class="swz-badge swz-badge-warn">Deleted locally</span></td></tr>';
            }
            $out .= $this->notice('warning',
                '<strong>' . count($touched) . ' file(s) on this install differ from what the installed release shipped</strong> '
                . '&mdash; usually hand-edited templates or custom tweaks. Updating overwrites them with the new versions. '
                . 'They are included in the automatic backup, but re-applying your changes afterwards is on you.');
            $out .= '<div class="swz-tablewrap"><table class="swz-table">'
                . '<thead><tr><th>File</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
        } else {
            $out .= $this->notice('info',
                'This install predates per-file change tracking, so local modifications cannot be ruled out automatically. '
                . 'Everything is backed up before the update; confirm below to proceed. From the next version on, '
                . 'hand-edited files are detected and listed here individually.');
        }

        $token = function_exists('generate_token') ? generate_token('WHMCS.admin.default') : '';
        $confirm = '';
        $btnAttrs = '';
        if ($needsConfirm) {
            $confirm = '<label style="display:flex;align-items:flex-start;gap:8px;margin:14px 0 0;font-size:12.5px;cursor:pointer;">'
                . '<input type="checkbox" name="swz_confirm_overwrite" value="1" style="margin-top:2px;" '
                . 'onchange="document.getElementById(\'swz-upd-btn\').disabled=!this.checked;" />'
                . '<span>I understand the files listed above will be overwritten by the new release (a full backup is made first).</span>'
                . '</label>';
            $btnAttrs = ' id="swz-upd-btn" disabled';
        } else {
            $btnAttrs = ' id="swz-upd-btn"';
        }
        $out .= '<form method="post" action="' . $this->esc($this->link(['swarmz_action' => 'update'])) . '">'
            . $token
            . '<input type="hidden" name="swz_do_update" value="1" />'
            . $confirm
            . '<p style="margin:14px 0 0;"><button type="submit" class="swz-btn"' . $btnAttrs . ' '
            . 'style="background:#4f46e5;border-color:#4f46e5;color:#fff;font-weight:600;">Update to v' . $this->esc((string) $info['version']) . '</button> '
            . '<span class="swz-muted" style="margin-left:8px;">Downloads the signed release, verifies its SHA-256 checksum, backs up the current files, then installs. Settings and data are untouched.</span></p>'
            . '</form>';

        return $out;
    }

    /** WHMCS root for display-shortening backup paths (best-effort). */
    private function whmcsRootForDisplay(): string
    {
        return defined('ROOTDIR') ? rtrim((string) ROOTDIR, '/') . '/' : '';
    }

    private function notice(string $type, string $html): string
    {
        $colors = [
            'info'    => ['#fafafa', '#e5e7eb', '#4b5563'],
            'success' => ['#f0fdf4', '#dcfce7', '#166534'],
            'warning' => ['#fffbeb', '#fef3c7', '#92400e'],
            'danger'  => ['#fef2f2', '#fee2e2', '#991b1b'],
        ];
        $c = isset($colors[$type]) ? $colors[$type] : $colors['info'];
        return '<div class="swz-notice" style="background:' . $c[0] . ';border:1px solid ' . $c[1] . ';color:' . $c[2] . ';">' . $html . '</div>';
    }

    private function money(float $n): string
    {
        return '$' . number_format($n, 2);
    }

    // ------------------------------------------------------------- admin sso

    /**
     * Mint a platform-sso redirect for a service's workspace and send the
     * admin's browser there (Location + exit). Reached from the "Open
     * workspace" link on the admin Service Details tab
     * (addonmodules.php?module=swarmz&swarmz_action=adminsso&serviceid=N —
     * WHMCS core already enforces admin auth + addon access control on
     * addonmodules.php). Key resolution mirrors the hooks: per-service server
     * Password first, addon key as fallback. Only failures return HTML.
     */
    private function renderAdminSso(): string
    {
        $back = '<div class="swz-toolbar"><div class="swz-tabs"></div><div class="swz-actions">'
            . '<a class="swz-btn" href="' . $this->esc($this->link([])) . '">&larr; Back to dashboard</a>'
            . '</div></div>';
        $title = '<h3 class="swz-section-title">Open workspace</h3>';

        $serviceId = (int) ($_REQUEST['serviceid'] ?? 0);
        if ($serviceId <= 0) {
            return $back . $title . $this->notice('danger', 'Missing or invalid <code>serviceid</code>.');
        }

        $tenantId = \WHMCS\Module\Server\Swarmz\Helpers::getTenantId($serviceId);
        if ($tenantId === null || $tenantId === '') {
            return $back . $title . $this->notice('warning',
                'Service #' . (int) $serviceId . ' has no Swarmz tenant yet — it is not provisioned '
                . '(or provisioning failed). Check the service&rsquo;s Module Log.'
            );
        }

        // Per-service key (server Password) → fall back to the console key.
        $key = \WHMCS\Module\Server\Swarmz\Helpers::resolveServiceServerKey($serviceId);
        if ($key === '') {
            $key = $this->apiKey;
        }
        if ($key === '') {
            return $back . $title . $this->notice('warning',
                'No API key available — set one in this console&rsquo;s settings or on the service&rsquo;s server.'
            );
        }

        try {
            $api = new \WHMCS\Module\Server\Swarmz\Api($key, $this->baseUrl);
            $body = [
                'external_ref' => \WHMCS\Module\Server\Swarmz\Helpers::buildExternalRef($serviceId),
                'tenant_id'    => $tenantId,
            ];
            $result = $api->postPlatform('platform-sso', $body);
            $redirect = isset($result['body']['redirectTo']) ? (string) $result['body']['redirectTo'] : '';

            if (function_exists('logModuleCall')) {
                logModuleCall('swarmz', 'AdminSSO', $body, $result['body'], $result['body'], ['sk_live_', 'sk_test_', 'Bearer ', $api->maskedKey()]);
            }

            if ($redirect !== '' && preg_match('#^https://#i', $redirect)) {
                header('Location: ' . $redirect);
                exit;
            }
            return $back . $title . $this->notice('danger',
                'The SSO endpoint did not return a redirect URL. See the Module Log entry <code>AdminSSO</code>.'
            );
        } catch (\WHMCS\Module\Server\Swarmz\SwarmzApiException $e) {
            $status = $e->getStatusCode();
            $why = $status === 409 ? 'the workspace is suspended'
                : ($status === 410 ? 'the workspace was terminated'
                : ($status === 401 ? 'the API key was rejected'
                : 'the API returned HTTP ' . (int) $status));
            return $back . $title . $this->notice('warning',
                'Could not sign in to workspace for service #' . (int) $serviceId . ' &mdash; ' . $why . '.'
            );
        } catch (\Throwable $e) {
            return $back . $title . $this->notice('danger',
                'SSO failed: <code>' . $this->esc($this->scrub($e->getMessage())) . '</code>'
            );
        }
    }

    // ---------------------------------------------------------- credit packs

    /**
     * "Credit Packs" page — map WHMCS Product Addons to the Swarmz top-up
     * credits they grant. The host creates ordinary Product Addons (no
     * provisioning module needed), then sets the credit amount per addon
     * here. Every PAID invoice line for a mapped addon grants once
     * (idempotent per invoice line — recurring addons re-grant each renewal).
     */
    private function renderCreditPacks(): string
    {
        if (!class_exists('\\WHMCS\\Module\\Addon\\Swarmz\\CreditPacks')) {
            $path = __DIR__ . '/CreditPacks.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        $back = '<div class="swz-toolbar"><div class="swz-tabs"></div><div class="swz-actions">'
            . '<a class="swz-btn" href="' . $this->esc($this->link([])) . '">&larr; Back to dashboard</a>'
            . '</div></div>';
        $title = '<h3 class="swz-section-title">Credit packs</h3>';

        if (!class_exists('\\WHMCS\\Module\\Addon\\Swarmz\\CreditPacks')) {
            return $back . $title . $this->notice('danger', 'CreditPacks library missing — reinstall the console addon.');
        }
        // Lazy schema: robust even when activate/upgrade never ran on ≥ 1.11.0.
        CreditPacks::ensureSchema();

        // Pack catalog from Swarmz — the plan builder (Dashboard → Settings →
        // Plans → Credit packs) is the source of truth. This page is the
        // catalog's WHMCS mirror (v1.20.0): one row per pack, showing whether
        // and how it is sold as a WHMCS Product Addon. Degrades gracefully: an
        // unreachable catalog leaves existing mappings on their cached amounts.
        $catalog = [];
        $catalogError = '';
        if ($this->apiKey === '') {
            $catalogError = 'Set your API Key in the module settings to load your Swarmz packs.';
        } else {
            try {
                $api = new \WHMCS\Module\Server\Swarmz\Api($this->apiKey, $this->baseUrl);
                $catalog = $api->listCreditPacks();
            } catch (\Throwable $e) {
                $catalogError = 'Could not reach your Swarmz pack catalog right now &mdash; '
                    . 'already-mapped packs keep working with their cached amounts.';
            }
        }
        $byCode = [];
        foreach ($catalog as $p) {
            $code = (string) ($p['code'] ?? '');
            $pCredits = (int) ($p['credits'] ?? 0);
            if ($code !== '' && $pCredits > 0) {
                $byCode[$code] = [
                    'name'        => (string) (($p['name'] ?? '') !== '' ? $p['name'] : $code),
                    'credits'     => $pCredits,
                    'cycle'       => (string) ($p['billing_cycle'] ?? 'onetime'),
                    'description' => (string) ($p['description'] ?? ''),
                    'price_cents' => (int) ($p['price_cents'] ?? 0),
                    'currency'    => (string) ($p['currency'] ?? 'USD'),
                ];
            }
        }
        // Keep cached amounts in step with the catalog whenever a human looks.
        if (!empty($byCode)) {
            CreditPacks::refreshFromCatalog($catalog);
        }

        $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $actionNotices = [];

        if ($isPost && isset($_POST['swz_unlink'])) {
            if (function_exists('check_token')) {
                check_token('WHMCS.admin.default');
            }
            $addonId = (int) $_POST['swz_unlink'];
            if ($addonId > 0) {
                CreditPacks::set($addonId, 0);
                $actionNotices[] = ['success',
                    'Unlinked. The WHMCS addon itself was not touched &mdash; hide or retire it in WHMCS if you no longer sell it.'];
            }
        }

        if ($isPost && isset($_POST['swz_link_pack'], $_POST['swz_link_addon'])) {
            if (function_exists('check_token')) {
                check_token('WHMCS.admin.default');
            }
            $code = (string) $_POST['swz_link_pack'];
            $addonId = (int) $_POST['swz_link_addon'];
            if ($addonId > 0 && isset($byCode[$code])) {
                CreditPacks::set($addonId, $byCode[$code]['credits'], $code, $byCode[$code]['name']);
                $actionNotices[] = ['success',
                    'Linked &mdash; the addon now grants ' . number_format($byCode[$code]['credits'])
                    . ' credits as &ldquo;' . $this->esc($byCode[$code]['name']) . '&rdquo;.'];
            } elseif ($addonId > 0) {
                $actionNotices[] = ['warning', 'That pack is not in your Swarmz catalog &mdash; nothing linked.'];
            }
        }

        if ($isPost && isset($_POST['swz_create_addon'])) {
            if (function_exists('check_token')) {
                check_token('WHMCS.admin.default');
            }
            $code = (string) $_POST['swz_create_addon'];
            if (isset($byCode[$code])) {
                $actionNotices[] = $this->createDraftAddonForPack($code, $byCode[$code]);
            } else {
                $actionNotices[] = ['warning', 'That pack is not in your Swarmz catalog &mdash; nothing created.'];
            }
        }

        $saved = null;
        $warnings = [];
        if ($isPost && isset($_POST['swz_packs_save'])) {
            // WHMCS admin CSRF token (belt and braces — validate when available).
            if (function_exists('check_token')) {
                check_token('WHMCS.admin.default');
            }
            // Existing state BEFORE applying — needed to keep a mapping whose
            // pack has vanished from the catalog (never silently unmap it).
            $existing = [];
            foreach (CreditPacks::listAddons() as $row) {
                $existing[(int) $row['addon_id']] = $row;
            }
            $sel = isset($_POST['swz_pack_map']) && is_array($_POST['swz_pack_map'])
                ? $_POST['swz_pack_map'] : [];
            $cust = isset($_POST['swz_pack_credits']) && is_array($_POST['swz_pack_credits'])
                ? $_POST['swz_pack_credits'] : [];
            $count = 0;
            foreach ($sel as $addonId => $choice) {
                $addonId = (int) $addonId;
                $choice = (string) $choice;
                if ($choice === 'custom') {
                    // blank / non-numeric / 0 → unmapped
                    CreditPacks::set($addonId, max(0, (int) ($cust[$addonId] ?? 0)));
                } elseif (strpos($choice, 'code:') === 0) {
                    $code = substr($choice, 5);
                    $prev = $existing[$addonId] ?? null;
                    if (isset($byCode[$code])) {
                        CreditPacks::set($addonId, $byCode[$code]['credits'], $code, $byCode[$code]['name']);
                    } elseif ($prev && ($prev['pack_code'] ?? '') === $code && (int) $prev['credits'] > 0) {
                        // Pack gone from the catalog but already mapped: keep
                        // the cached amount rather than breaking a live seller.
                        CreditPacks::set($addonId, (int) $prev['credits'], $code, (string) ($prev['pack_name'] ?? ''));
                    } else {
                        $warnings[] = 'Pack &ldquo;' . $this->esc($code) . '&rdquo; no longer exists on Swarmz &mdash; addon #' . $addonId . ' left unchanged.';
                        continue;
                    }
                } else {
                    CreditPacks::set($addonId, 0); // not a credit pack
                }
                $count++;
            }
            $saved = $count;
        }

        $intro = '<p class="swz-lede">Your Swarmz packs, and how each one is sold in WHMCS. '
            . 'Define packs on Swarmz under Dashboard &rarr; Settings &rarr; Plans &rarr; <strong>Credit packs</strong> '
            . '&mdash; the catalog decides what each pack is worth, and every sale is counted per pack right there. '
            . 'For each pack below, <strong>Create addon</strong> makes a ready-linked WHMCS Product Addon as a '
            . '<strong>hidden draft</strong>: check its price in WHMCS and untick &ldquo;Hidden&rdquo; when you are happy '
            . '&mdash; nothing is sellable until you do. Already have an addon for it? Link it instead. '
            . 'When a customer pays for a linked addon, the credits land in their workspace within seconds, '
            . 'amounts stay in sync with your catalog automatically, and a double-paid invoice can never double-grant.</p>';

        $rows = CreditPacks::listAddons();
        $linkedByCode = [];
        $customRows = [];
        $unmappedRows = [];
        foreach ($rows as $r) {
            if ($r['pack_code'] !== '') {
                $linkedByCode[$r['pack_code']] = $r;
            } elseif ($r['credits'] > 0) {
                $customRows[] = $r;
            } else {
                $unmappedRows[] = $r;
            }
        }

        $token = function_exists('generate_token') ? generate_token('WHMCS.admin.default') : '';
        $showAll = !empty($_REQUEST['swz_all']);

        $notice = '';
        if ($catalogError !== '') {
            $notice .= $this->notice('warning', $catalogError);
        } elseif (empty($byCode) && !$showAll && empty($linkedByCode)) {
            $notice .= $this->notice('info',
                'No credit packs defined on Swarmz yet. Add them under <strong>Dashboard &rarr; Settings '
                . '&rarr; Plans &rarr; Credit packs</strong> and they appear here, one click away from selling.'
            );
        }
        foreach ($actionNotices as $an) {
            $notice .= $this->notice($an[0], $an[1]);
        }
        foreach ($warnings as $w) {
            $notice .= $this->notice('warning', $w);
        }
        if ($saved !== null) {
            $notice .= $this->notice('success', 'Mappings saved.');
        }

        if (!$showAll) {
            return $back . $title . $intro . $notice
                . $this->renderPackCatalogView($byCode, $linkedByCode, $customRows, $unmappedRows, $token);
        }

        // ── Advanced view: every Product Addon, mapped by hand ─────────────
        if (empty($rows)) {
            return $back . $title . $intro . $notice . $this->notice('info',
                'No Product Addons exist yet. Use <strong>Create addon</strong> on the packs view, or create one '
                . 'under <strong>Setup &rarr; Products/Services &rarr; Product Addons</strong>.'
            );
        }

        $body = '';
        foreach ($rows as $r) {
            $assigned = array_filter(array_map('trim', explode(',', $r['packages'])));
            $cycle = $this->esc($r['billingcycle'] !== '' ? ucfirst($r['billingcycle']) : 'One time');
            if (strtolower($r['billingcycle']) === 'free') {
                $cycle .= ' <span class="swz-badge swz-badge-info" title="A free addon never produces an invoice, so it grants ONCE when the addon is activated instead of on payment">grants on activation</span>';
            }
            if ($r['pack_code'] !== '') {
                $packLabel = $r['pack_name'] !== '' ? $r['pack_name'] : $r['pack_code'];
                $mapped = '<span class="swz-badge swz-badge-info">' . $this->esc($packLabel) . '</span> '
                    . '<span class="swz-muted">' . number_format($r['credits']) . ' credits</span>';
            } elseif ($r['credits'] > 0) {
                $mapped = '<span class="swz-badge swz-badge-info">' . number_format($r['credits']) . ' credits</span> '
                    . '<span class="swz-muted">custom</span>';
            } else {
                $mapped = '<span class="swz-muted">&mdash;</span>';
            }
            $body .= '<tr>'
                . '<td><span class="swz-strong">' . $this->esc($r['name']) . '</span>'
                . ' <span class="swz-muted">#' . (int) $r['addon_id'] . '</span></td>'
                . '<td>' . $cycle . '</td>'
                . '<td>' . $this->storeBadge($r) . '</td>'
                . '<td class="swz-num">' . count($assigned) . '</td>'
                . '<td>' . $mapped . '</td>'
                . '<td>' . $this->packMapCell($r, $byCode) . '</td>'
                . '</tr>';
        }

        $form = '<p class="swz-note" style="margin:0 0 10px;">Advanced view: every Product Addon in your WHMCS. '
            . '<a href="' . $this->esc($this->link(['swarmz_action' => 'creditpacks'])) . '">Back to your packs</a>.</p>'
            . '<form method="post" action="' . $this->esc($this->link(['swarmz_action' => 'creditpacks', 'swz_all' => 1])) . '">'
            . $token
            . '<input type="hidden" name="swz_packs_save" value="1" />'
            . '<div class="swz-tablewrap"><table class="swz-table">'
            . '<thead><tr><th>Product addon</th><th>Billing cycle</th><th>Store</th>'
            . '<th class="swz-num">Products</th><th>Currently grants</th><th>Sells as</th></tr></thead>'
            . '<tbody>' . $body . '</tbody>'
            . '</table></div>'
            . '<p class="swz-note">&ldquo;Products&rdquo; is how many of your products the addon is assigned to &mdash; '
            . 'the client-area &ldquo;buy more&rdquo; link only appears for customers whose product '
            . 'has at least one mapped addon that is not Hidden. Pick <strong>Not a credit pack</strong> '
            . 'to unmap an addon (a custom amount of 0 unmaps too). Credits already granted are never touched.</p>'
            . '<p style="margin:14px 0 0;"><button type="submit" class="swz-btn" '
            . 'style="background:#4f46e5;border-color:#4f46e5;color:#fff;font-weight:600;">Save mappings</button></p>'
            . '</form>' . $this->packMapToggleScript();

        return $back . $title . $intro . $notice . $form;
    }

    /**
     * Catalog-first packs view (v1.20.0): one row per Swarmz pack — plus any
     * mapping whose pack has left the catalog, kept visible and truthful —
     * with the linked WHMCS addon (and its store status) or the one-click
     * actions to get there. Custom-amount mappings render in their own small
     * table; the by-addon table stays reachable as the advanced view.
     *
     * @param array<string,array<string,mixed>> $byCode       catalog packs by code
     * @param array<string,array<string,mixed>> $linkedByCode pack_code → mapped addon row
     * @param array<int,array<string,mixed>>    $customRows   custom-amount mappings
     * @param array<int,array<string,mixed>>    $unmappedRows addons with no mapping at all
     */
    private function renderPackCatalogView(array $byCode, array $linkedByCode, array $customRows, array $unmappedRows, string $token): string
    {
        $inputStyle = 'padding:5px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;';
        $btnStyle = 'background:#4f46e5;border-color:#4f46e5;color:#fff;font-weight:600;';

        $codes = array_keys($byCode);
        foreach (array_keys($linkedByCode) as $code) {
            if (!isset($byCode[$code])) {
                $codes[] = $code;
            }
        }

        $body = '';
        foreach ($codes as $code) {
            $inCatalog = isset($byCode[$code]);
            $linked = $linkedByCode[$code] ?? null;
            if ($inCatalog) {
                $p = $byCode[$code];
                $name = $p['name'];
                $credits = (int) $p['credits'];
                $price = $p['price_cents'] > 0
                    ? number_format($p['price_cents'] / 100, 2) . ' ' . $this->esc($p['currency'])
                        . ($p['cycle'] === 'monthly' ? ' /mo' : '')
                    : 'Free';
                $cycle = $p['cycle'] === 'monthly' ? 'Monthly' : 'One time';
            } else {
                $name = $linked['pack_name'] !== '' ? $linked['pack_name'] : $code;
                $credits = (int) $linked['credits'];
                $price = '&mdash;';
                $cycle = '<span class="swz-badge swz-badge-warn" title="This mapping points at a pack that is no longer in your Swarmz catalog. It keeps granting its cached amount.">Not in catalog</span>';
            }

            if ($linked) {
                $sold = '<span class="swz-strong">' . $this->esc($linked['name']) . '</span> '
                    . '<span class="swz-muted">#' . (int) $linked['addon_id'] . '</span> '
                    . $this->storeBadge($linked);
                $action = '<form method="post" style="display:inline;">' . $token
                    . '<input type="hidden" name="swz_unlink" value="' . (int) $linked['addon_id'] . '" />'
                    . '<button type="submit" class="swz-btn">Unlink</button></form>';
            } else {
                $sold = '<span class="swz-muted">Not in WHMCS yet</span>';
                // Adoption first: an unmapped addon already named like the
                // pack gets linked, never duplicated.
                $match = null;
                foreach ($unmappedRows as $u) {
                    if (strcasecmp($u['name'], $name) === 0) {
                        $match = $u;
                        break;
                    }
                }
                if ($match) {
                    $action = '<form method="post" style="display:inline;">' . $token
                        . '<input type="hidden" name="swz_link_pack" value="' . $this->esc($code) . '" />'
                        . '<input type="hidden" name="swz_link_addon" value="' . (int) $match['addon_id'] . '" />'
                        . '<button type="submit" class="swz-btn" style="' . $btnStyle . '">Link existing addon #' . (int) $match['addon_id'] . '</button></form>';
                } else {
                    $action = '<form method="post" style="display:inline;">' . $token
                        . '<input type="hidden" name="swz_create_addon" value="' . $this->esc($code) . '" />'
                        . '<button type="submit" class="swz-btn" style="' . $btnStyle . '">Create addon</button></form>';
                    if (!empty($unmappedRows)) {
                        $opts = '<option value="">or link existing&hellip;</option>';
                        foreach ($unmappedRows as $u) {
                            $opts .= '<option value="' . (int) $u['addon_id'] . '">' . $this->esc($u['name']) . ' #' . (int) $u['addon_id'] . '</option>';
                        }
                        $action .= ' <form method="post" style="display:inline;">' . $token
                            . '<input type="hidden" name="swz_link_pack" value="' . $this->esc($code) . '" />'
                            . '<select name="swz_link_addon" style="' . $inputStyle . 'max-width:180px;">' . $opts . '</select> '
                            . '<button type="submit" class="swz-btn">Link</button></form>';
                    }
                }
            }

            $body .= '<tr>'
                . '<td><span class="swz-strong">' . $this->esc($name) . '</span> <span class="swz-muted">' . $this->esc($code) . '</span></td>'
                . '<td class="swz-num">' . number_format($credits) . '</td>'
                . '<td>' . $price . '</td>'
                . '<td>' . $cycle . '</td>'
                . '<td>' . $sold . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="6"><span class="swz-muted">No packs yet.</span></td></tr>';
        }

        $out = '<div class="swz-tablewrap"><table class="swz-table">'
            . '<thead><tr><th>Swarmz pack</th><th class="swz-num">Credits</th><th>Your price</th>'
            . '<th>Billing</th><th>Sold in WHMCS as</th><th>Action</th></tr></thead>'
            . '<tbody>' . $body . '</tbody></table></div>'
            . '<p class="swz-note">A created addon starts <strong>hidden</strong>, assigned to your Swarmz products '
            . 'and kept off the initial order form: open <a href="configaddons.php">Setup &rarr; Products/Services '
            . '&rarr; Product Addons</a>, check the price, and untick <strong>Hidden</strong> to start selling. '
            . 'Sales counts appear per pack in your Swarmz dashboard.</p>';

        if (!empty($customRows)) {
            $rowsHtml = '';
            foreach ($customRows as $r) {
                $rowsHtml .= '<tr>'
                    . '<td><span class="swz-strong">' . $this->esc($r['name']) . '</span> '
                    . '<span class="swz-muted">#' . (int) $r['addon_id'] . '</span> ' . $this->storeBadge($r) . '</td>'
                    . '<td class="swz-num">' . number_format($r['credits']) . ' credits</td>'
                    . '<td>' . $this->packMapCell($r, $byCode) . '</td>'
                    . '</tr>';
            }
            $out .= '<h4 style="margin:22px 0 4px;font-size:14px;">Custom amounts</h4>'
                . '<p class="swz-note" style="margin:0 0 8px;">Addons granting a hand-typed amount instead of a catalog pack.</p>'
                . '<form method="post">' . $token
                . '<input type="hidden" name="swz_packs_save" value="1" />'
                . '<div class="swz-tablewrap"><table class="swz-table">'
                . '<thead><tr><th>Product addon</th><th class="swz-num">Currently grants</th><th>Sells as</th></tr></thead>'
                . '<tbody>' . $rowsHtml . '</tbody></table></div>'
                . '<p style="margin:12px 0 0;"><button type="submit" class="swz-btn" style="' . $btnStyle . '">Save</button></p>'
                . '</form>' . $this->packMapToggleScript();
        }

        $out .= '<p class="swz-note" style="margin-top:18px;">Need something unusual &mdash; a custom amount, or mapping '
            . 'any addon by hand? <a href="' . $this->esc($this->link(['swarmz_action' => 'creditpacks', 'swz_all' => 1]))
            . '">Open the advanced by-addon view</a>.</p>';
        return $out;
    }

    /** Store-visibility badge for a Product Addon row (hidden/retired/showorder). */
    private function storeBadge(array $r): string
    {
        if (!empty($r['retired'])) {
            return '<span class="swz-badge swz-badge-neutral">Retired</span>';
        }
        if (!empty($r['hidden'])) {
            return '<span class="swz-badge swz-badge-warn" title="The addon\'s Hidden checkbox is ticked — existing customers cannot buy it from the store">Hidden</span>';
        }
        if (!empty($r['showorder'])) {
            return '<span class="swz-badge swz-badge-ok">In store + order form</span>';
        }
        return '<span class="swz-badge swz-badge-ok" title="Buyable from the client-area addon store; not offered during initial checkout (Show on Order Form is unticked)">In store</span>';
    }

    /** The "Sells as" select + custom-amount input for one addon row. */
    private function packMapCell(array $r, array $byCode): string
    {
        $id = (int) $r['addon_id'];
        $isCustom = $r['credits'] > 0 && $r['pack_code'] === '';
        $opts = '<option value="">Not a credit pack</option>';
        foreach ($byCode as $code => $p) {
            $selAttr = ($r['pack_code'] === $code) ? ' selected' : '';
            $label = $p['name'] . ' — ' . number_format($p['credits']) . ' credits'
                . ($p['cycle'] === 'monthly' ? ' (monthly)' : ' (one-time)');
            $opts .= '<option value="code:' . $this->esc($code) . '"' . $selAttr . '>'
                . $this->esc($label) . '</option>';
        }
        if ($r['pack_code'] !== '' && !isset($byCode[$r['pack_code']])) {
            // Mapped to a pack the catalog no longer lists (archived, or the
            // catalog is unreachable): keep it selectable + truthful.
            $label = ($r['pack_name'] !== '' ? $r['pack_name'] : $r['pack_code'])
                . ' — ' . number_format($r['credits']) . ' credits (not in your Swarmz catalog)';
            $opts .= '<option value="code:' . $this->esc($r['pack_code']) . '" selected>'
                . $this->esc($label) . '</option>';
        }
        $opts .= '<option value="custom"' . ($isCustom ? ' selected' : '') . '>Custom amount&hellip;</option>';
        return '<select name="swz_pack_map[' . $id . ']" data-swz-addon="' . $id . '" '
            . 'style="max-width:250px;padding:5px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;">'
            . $opts . '</select> '
            . '<input type="number" min="0" step="1" name="swz_pack_credits[' . $id . ']" '
            . 'value="' . ($isCustom ? (int) $r['credits'] : '') . '" placeholder="credits" '
            . 'style="width:100px;padding:5px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;'
            . 'text-align:right;' . ($isCustom ? '' : 'display:none;') . '" />';
    }

    /** Toggles the custom-credits input next to each mapping select. */
    private function packMapToggleScript(): string
    {
        return '<script>document.querySelectorAll("select[data-swz-addon]").forEach(function(s){'
            . 'var input=s.parentNode.querySelector("input[type=number]");'
            . 'var sync=function(){if(input){input.style.display=s.value==="custom"?"":"none";}};'
            . 's.addEventListener("change",sync);sync();});</script>';
    }

    /**
     * One-click "Create addon" (v1.20.0): makes the WHMCS Product Addon for a
     * catalog pack as a HIDDEN DRAFT — correct billing cycle, assigned to
     * every Swarmz product, price prefilled from the catalog — and links it.
     * Hidden means nothing is sellable until the host reviews the price in
     * WHMCS and unticks the checkbox, so an imperfect prefill can never sell
     * credits at the wrong price. An addon already carrying the pack's name
     * is adopted (linked) instead — never duplicated.
     *
     * @param array<string,mixed> $pack catalog entry (name/credits/cycle/description/price_cents/currency)
     * @return array{0:string,1:string} [notice type, message html]
     */
    private function createDraftAddonForPack(string $code, array $pack): array
    {
        try {
            $existing = Capsule::table('tbladdons')->where('name', $pack['name'])->first(['id']);
            if ($existing) {
                CreditPacks::set((int) $existing->id, (int) $pack['credits'], $code, $pack['name']);
                return ['success', 'An addon named &ldquo;' . $this->esc($pack['name'])
                    . '&rdquo; already existed &mdash; linked it instead of creating a duplicate.'];
            }

            $pids = [];
            foreach (Capsule::table('tblproducts')->where('servertype', 'swarmz')->get(['id']) as $p) {
                $pids[] = (int) $p->id;
            }
            $cycle = 'onetime';
            if ((int) $pack['price_cents'] <= 0) {
                $cycle = 'free';
            } elseif ($pack['cycle'] === 'monthly') {
                $cycle = 'recurring';
            }
            $data = [
                'name'         => $pack['name'],
                'description'  => (string) $pack['description'],
                'billingcycle' => $cycle,
                'packages'     => implode(',', $pids),
                'showorder'    => 0, // top-ups are bought later, not at initial checkout
                'hidden'       => 1, // DRAFT — the host reviews, then unticks
            ];
            try {
                $addonId = (int) Capsule::table('tbladdons')->insertGetId($data);
            } catch (\Throwable $e) {
                // Progressive fallback for schema variance across WHMCS 8.x.
                unset($data['description']);
                $addonId = (int) Capsule::table('tbladdons')->insertGetId($data);
            }
            if ($addonId <= 0) {
                return ['danger', 'WHMCS did not return an id for the new addon &mdash; nothing linked.'];
            }

            // Price rows per currency: the pack price lands in the default
            // currency, converted by each currency's rate. The draft stays
            // hidden, so a conversion the host disagrees with gets fixed in
            // WHMCS before anything can sell.
            $price = max(0, (int) $pack['price_cents']) / 100;
            try {
                foreach (Capsule::table('tblcurrencies')->get() as $cur) {
                    $rate = (float) ($cur->rate ?? 1);
                    if ($rate <= 0) {
                        $rate = 1.0;
                    }
                    $row = [
                        'type'     => 'addon',
                        'currency' => (int) $cur->id,
                        'relid'    => $addonId,
                        'monthly'  => number_format($price * $rate, 2, '.', ''),
                    ];
                    try {
                        Capsule::table('tblpricing')->insert($row + [
                            'msetupfee' => '0.00', 'qsetupfee' => '0.00', 'ssetupfee' => '0.00',
                            'asetupfee' => '0.00', 'bsetupfee' => '0.00', 'tsetupfee' => '0.00',
                            'quarterly' => '-1.00', 'semiannually' => '-1.00', 'annually' => '-1.00',
                            'biennially' => '-1.00', 'triennially' => '-1.00',
                        ]);
                    } catch (\Throwable $e) {
                        Capsule::table('tblpricing')->insert($row);
                    }
                }
            } catch (\Throwable $e) {
                // Pricing is host-reviewed anyway; the draft note covers it.
            }

            CreditPacks::set($addonId, (int) $pack['credits'], $code, $pack['name']);
            return ['success', 'Draft addon <strong>#' . $addonId . ' ' . $this->esc($pack['name'])
                . '</strong> created and linked &mdash; hidden until you finish it. '
                . '<a href="configaddons.php">Open Product Addons</a>, check the price'
                . (empty($pids) ? ', assign it to your Swarmz product' : '')
                . ', and untick <strong>Hidden</strong> to start selling.'];
        } catch (\Throwable $e) {
            return ['danger', 'Could not create the addon: ' . $this->esc($e->getMessage())];
        }
    }

    /**
     * Render GitHub release notes as HTML — the tiny, SAFE subset the release
     * template actually uses. The whole text is HTML-escaped FIRST; only then
     * are markdown patterns rewritten, so no tag in the notes can survive:
     * bullet lists, headings, bold, inline code, and https links.
     */
    private function mdNotes(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $out = '';
        $inList = false;
        foreach (explode("\n", $md) as $line) {
            $t = rtrim($line);
            $isItem = (bool) preg_match('/^\s*[-*]\s+/', $t);
            if ($inList && !$isItem) {
                $out .= '</ul>';
                $inList = false;
            }
            if (trim($t) === '') {
                continue;
            }
            if ($isItem) {
                if (!$inList) {
                    $out .= '<ul style="margin:6px 0 10px;padding-left:20px;">';
                    $inList = true;
                }
                $out .= '<li style="margin:3px 0;">' . $this->mdInline(preg_replace('/^\s*[-*]\s+/', '', $t)) . '</li>';
                continue;
            }
            if (preg_match('/^#{1,4}\s+(.*)$/', $t, $m)) {
                $out .= '<p style="margin:12px 0 4px;font-weight:650;">' . $this->mdInline($m[1]) . '</p>';
                continue;
            }
            $out .= '<p style="margin:6px 0;">' . $this->mdInline($t) . '</p>';
        }
        if ($inList) {
            $out .= '</ul>';
        }
        return $out;
    }

    /** Inline markdown on ONE escaped line: bold, code, bare https links. */
    private function mdInline(string $t): string
    {
        $t = $this->esc($t);
        $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
        $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
        // [label](https://…) — escaped text, so parens/brackets are literal.
        $t = preg_replace(
            '/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/',
            '<a href="$2" target="_blank" rel="noopener">$1</a>',
            $t
        );
        return $t;
    }

    /**
     * "Checkout flow" chooser on the Appearance page — how the client-area
     * packs popup checks out. One radio card per flow; the value drives
     * swarmz_buypack()'s handoff.
     */
    private function renderCheckoutFlowSection(): string
    {
        $cur = \WHMCS\Module\Server\Swarmz\Helpers::checkoutFlow();
        $flows = [
            'invoice' => ['Direct to invoice', 'The module places the order and the customer pays the invoice. Works with every theme and order form; free packs complete instantly. Recommended.'],
            'standard' => ['Standard WHMCS cart', 'Classic cart deep link (cart.php). For stock WHMCS order forms. Themed order forms may reroute it.'],
            'lagom' => ['Lagom Smart Order Form', 'Sends customers to Lagom&rsquo;s addon store page to pick the pack and check out in Lagom&rsquo;s own flow (Lagom has no per-addon link).'],
        ];
        $cards = '';
        foreach ($flows as $key => $info) {
            $on = $key === $cur ? ' swz-on' : '';
            $cards .= '<label class="swz-theme' . $on . '" style="min-height:auto;">'
                . '<input type="radio" name="swz_flow" value="' . $this->esc($key) . '"' . ($key === $cur ? ' checked' : '') . ' onchange="swzSel(this)" />'
                . '<p class="swz-theme-name" style="margin-top:0;">' . $info[0] . '</p>'
                . '<p class="swz-theme-desc">' . $info[1] . '</p>'
                . '</label>';
        }
        return '<div class="swz-section"><h3 class="swz-section-title">Checkout flow</h3>'
            . '<p class="swz-section-sub">How the &ldquo;Buy more&rdquo; popup checks out a pack. Pick the one matching your order form.</p>'
            . '<div class="swz-themes">' . $cards . '</div></div>';
    }

    /**
     * Persist one console-managed addon setting (same storage the WHMCS
     * module-settings form uses), so Helpers::addonSetting() keeps reading it.
     */
    private function saveAddonSetting(string $key, string $value): void
    {
        try {
            \WHMCS\Database\Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => 'swarmz', 'setting' => $key],
                ['value' => $value]
            );
        } catch (\Throwable $e) {
            // Surface nothing — the page re-reads and shows the real state.
        }
    }

    /**
     * "Appearance" page — how the customer-facing service panel looks.
     * Theme cards with live-ish CSS previews, six accent schemes, and an
     * optional custom hex. Saves to the same settings the template reads.
     */
    private function renderAppearance(): string
    {
        $back = '<div class="swz-toolbar"><div class="swz-tabs"></div><div class="swz-actions">'
            . '<a class="swz-btn" href="' . $this->esc($this->link([])) . '">&larr; Back to dashboard</a>'
            . '</div></div>';
        $title = '<h3 class="swz-section-title">Appearance</h3>';

        $saved = '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['swz_appearance_save'])) {
            if (function_exists('check_token')) {
                check_token('WHMCS.admin.default');
            }
            $theme = strtolower(trim((string) ($_POST['swz_theme'] ?? 'classic')));
            if (!in_array($theme, \WHMCS\Module\Server\Swarmz\Helpers::CLIENT_THEMES, true)) {
                $theme = 'classic';
            }
            $scheme = strtolower(trim((string) ($_POST['swz_scheme'] ?? 'theme')));
            if ($scheme !== 'theme' && !isset(\WHMCS\Module\Server\Swarmz\Helpers::ACCENT_SCHEMES[$scheme])) {
                $scheme = 'theme';
            }
            $hex = trim((string) ($_POST['swz_hex'] ?? ''));
            $hex = preg_match('/^#?[0-9a-fA-F]{6}$/', $hex) ? ('#' . ltrim($hex, '#')) : '';
            $flow = strtolower(trim((string) ($_POST['swz_flow'] ?? 'invoice')));
            if (!in_array($flow, \WHMCS\Module\Server\Swarmz\Helpers::CHECKOUT_FLOWS, true)) {
                $flow = 'invoice';
            }
            $this->saveAddonSetting('Client Theme', $theme);
            $this->saveAddonSetting('Color Scheme', $scheme);
            $this->saveAddonSetting('Accent Color', $hex);
            $this->saveAddonSetting('Checkout Flow', $flow);
            $saved = $this->notice('success', 'Saved. Your customers see the new look on their next page load.');
        }

        $curTheme = \WHMCS\Module\Server\Swarmz\Helpers::clientTheme();
        $curScheme = strtolower(trim((string) \WHMCS\Module\Server\Swarmz\Helpers::addonSetting('Color Scheme', 'theme')));
        if ($curScheme !== 'theme' && !isset(\WHMCS\Module\Server\Swarmz\Helpers::ACCENT_SCHEMES[$curScheme])) {
            $curScheme = 'theme';
        }
        $curHexRaw = trim((string) \WHMCS\Module\Server\Swarmz\Helpers::addonSetting('Accent Color', ''));
        $curHex = preg_match('/^#?[0-9a-fA-F]{6}$/', $curHexRaw) ? ('#' . ltrim($curHexRaw, '#')) : '';

        $intro = '<p class="swz-lede">How the service panel looks to <strong>your customers</strong>. '
            . 'Pick a layout, then an accent color &mdash; changes apply instantly, no files to edit.</p>';

        // Mini previews: tiny hand-drawn mocks per theme (pure inline CSS).
        $previews = [
            'classic' => '<div style="padding:9px;"><div style="height:22px;border:1px solid #ddd;border-radius:5px;background:#f6f6f7;margin-bottom:7px;"></div><div style="display:flex;gap:6px;">' . str_repeat('<div style="flex:1;height:44px;border:1px solid #e2e2e6;border-radius:5px;background:#fff;"></div>', 3) . '</div></div>',
            'swarmz' => '<div style="padding:9px;"><div style="height:22px;border:1px solid #e5e5ea;border-left:3px solid #f97316;border-radius:3px;background:#fff;margin-bottom:7px;"></div><div style="display:flex;gap:6px;">' . str_repeat('<div style="flex:1;height:44px;border:1px solid #e9e9ee;border-radius:4px;background:#fff;"></div>', 3) . '</div></div>',
            'cupertino' => '<div style="padding:9px;text-align:center;"><div style="height:26px;border-radius:9px;background:#ededf0;margin-bottom:7px;"></div><div style="display:flex;gap:7px;">' . str_repeat('<div style="flex:1;height:40px;border-radius:11px;background:#ededf0;"></div>', 2) . '</div></div>',
            'pulse' => '<div style="padding:9px;"><div style="height:24px;border-radius:7px;background:linear-gradient(115deg,#f97316,#c2410c);margin-bottom:7px;"></div><div style="display:flex;gap:6px;"><div style="flex:2;height:40px;border-radius:7px;background:#fff4ec;border:1px solid #fcd9bd;"></div><div style="flex:1;height:40px;border-radius:7px;background:#fff;border:1px solid #eee;"></div></div></div>',
            'carbon' => '<div style="padding:9px;background:#101214;height:100%;"><div style="height:16px;border-bottom:1px solid #2a2e33;margin-bottom:7px;"></div>' . str_repeat('<div style="height:12px;background:#171a1d;border:1px solid #23272c;border-radius:3px;margin-bottom:5px;"></div>', 3) . '</div>',
            'editorial' => '<div style="padding:11px 12px;"><div style="height:2px;background:#333;margin-bottom:9px;"></div><div style="display:flex;gap:12px;">' . str_repeat('<div style="flex:1;"><div style="height:5px;background:#e6e6e9;margin-bottom:7px;"></div><div style="font-family:Georgia,serif;font-size:19px;color:#444;">12</div></div>', 3) . '</div></div>',
        ];
        $blurbs = [
            'classic' => 'The quiet default. Neutral cards, works with every WHMCS theme.',
            'swarmz' => 'Flat and precise, hairline borders &mdash; like the Swarmz dashboard.',
            'cupertino' => 'Soft and rounded, centered hero &mdash; Apple-style calm.',
            'pulse' => 'A bold color hero and a featured balance card. High energy.',
            'carbon' => 'A sleek dark panel with dense console rows. Very techy.',
            'editorial' => 'No boxes at all &mdash; typographic, airy, hairline rules.',
        ];

        $cards = '';
        foreach (\WHMCS\Module\Server\Swarmz\Helpers::CLIENT_THEMES as $t) {
            $on = $t === $curTheme ? ' swz-on' : '';
            $cards .= '<label class="swz-theme' . $on . '">'
                . '<input type="radio" name="swz_theme" value="' . $this->esc($t) . '"' . ($t === $curTheme ? ' checked' : '') . ' onchange="swzSel(this)" />'
                . '<div class="swz-prev">' . ($previews[$t] ?? '') . '</div>'
                . '<p class="swz-theme-name">' . $this->esc(ucfirst($t)) . '</p>'
                . '<p class="swz-theme-desc">' . ($blurbs[$t] ?? '') . '</p>'
                . '</label>';
        }

        $schemes = ['theme' => ''] + \WHMCS\Module\Server\Swarmz\Helpers::ACCENT_SCHEMES;
        $swatches = '';
        foreach ($schemes as $name => $hex) {
            $on = $name === $curScheme ? ' swz-on' : '';
            $bg = $name === 'theme'
                ? 'background:conic-gradient(#f97316,#3b82f6,#16a34a,#ec4899,#f97316);'
                : 'background:' . $this->esc($hex) . ';';
            $swatches .= '<label class="swz-swatch' . $on . '" style="' . $bg . '" title="' . $this->esc($name) . '">'
                . '<input type="radio" name="swz_scheme" value="' . $this->esc($name) . '"' . ($name === $curScheme ? ' checked' : '') . ' onchange="swzSelSwatch(this)" />'
                . '</label>';
        }

        $token = function_exists('generate_token') ? generate_token('WHMCS.admin.default') : '';
        $form = '<form method="post" action="' . $this->esc($this->link(['swarmz_action' => 'appearance'])) . '">'
            . $token
            . '<input type="hidden" name="swz_appearance_save" value="1" />'
            . '<div class="swz-section"><h3 class="swz-section-title">Layout</h3>'
            . '<p class="swz-section-sub">Each one is a different composition, not just colors.</p>'
            . '<div class="swz-themes">' . $cards . '</div></div>'
            . '<div class="swz-section"><h3 class="swz-section-title">Accent</h3>'
            . '<p class="swz-section-sub">The rainbow dot lets each layout use its own default. Or type an exact brand color &mdash; it wins over the dots.</p>'
            . '<div class="swz-swatches">' . $swatches
            . '<input class="swz-hex" type="text" name="swz_hex" value="' . $this->esc($curHex) . '" placeholder="#7c3aed" maxlength="7" /></div></div>'
            . $this->renderCheckoutFlowSection()
            . '<button type="submit" class="swz-save">Save appearance</button>'
            . '</form>'
            . '<script>function swzSel(i){var n=i.closest(".swz-themes").querySelectorAll(".swz-theme");for(var k=0;k<n.length;k++){n[k].classList.remove("swz-on");}i.closest(".swz-theme").classList.add("swz-on");}'
            . 'function swzSelSwatch(i){var n=i.closest(".swz-swatches").querySelectorAll(".swz-swatch");for(var k=0;k<n.length;k++){n[k].classList.remove("swz-on");}i.closest(".swz-swatch").classList.add("swz-on");}</script>';

        return $back . $title . $intro . $saved . $form;
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

    /**
     * Self-contained inline stylesheet (no external fonts/CSS — must render
     * standalone inside the WHMCS admin theme).
     *
     * Palette (deliberately restrained): near-black/gray ink on white with
     * hairline borders; ONE accent (#4f46e5, the Swarmz indigo) reserved for
     * links and the active period tab. Status pills stay semantic but muted
     * (tinted background + dark text — never saturated fills).
     */
    private function styles(): string
    {
        return '<style>
/* Reseller Console — airy, flat, hairline; Swarmz orange accent. */
.swarmz-console{--acc:#f97316;--ink:#16181d;--mut:#6b7280;--dim:#9aa1ab;--line:#ececf0;--line2:#e2e4e9;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);}
.swarmz-console a{color:var(--acc);text-decoration:none;}
.swarmz-console a:hover{opacity:.8;text-decoration:none;}
.swarmz-console .swz-head{margin:6px 0 0;}
.swarmz-console .swz-title{display:none;}
.swarmz-console .swz-sub{margin:0;color:var(--mut);font-size:13px;max-width:720px;line-height:1.5;}
.swarmz-console .swz-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:16px 0 30px;}
.swarmz-console .swz-tabs{display:inline-flex;gap:2px;background:#f2f3f5;border-radius:9px;padding:3px;}
.swarmz-console .swz-tab{padding:6px 14px;font-size:12.5px;font-weight:500;color:var(--mut);text-decoration:none;border-radius:7px;}
.swarmz-console .swz-tab:hover{color:var(--ink);text-decoration:none;}
.swarmz-console .swz-tab-active,.swarmz-console .swz-tab-active:hover{background:#fff;color:var(--ink);font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.07);}
.swarmz-console .swz-actions{display:inline-flex;gap:6px;flex-wrap:wrap;}
.swarmz-console .swz-btn{display:inline-block;padding:6px 13px;border:1px solid var(--line2);border-radius:8px;background:#fff;color:#374151;text-decoration:none;font-size:12.5px;font-weight:500;}
.swarmz-console .swz-btn:hover{border-color:#cfd3da;color:var(--ink);text-decoration:none;}
.swarmz-console .swz-btn-sm{padding:3px 10px;font-size:12px;}
.swarmz-console .swz-section{margin:0 0 36px;}
.swarmz-console .swz-section-title{margin:0 0 2px;font-size:11.5px;font-weight:650;color:var(--dim);text-transform:uppercase;letter-spacing:.08em;}
.swarmz-console .swz-section-sub{margin:0 0 14px;color:var(--dim);font-size:12.5px;max-width:720px;line-height:1.5;}
.swarmz-console .swz-lede{margin:0 0 16px;color:var(--mut);font-size:13px;line-height:1.6;max-width:760px;}
.swarmz-console .swz-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:0 34px;margin:0 0 4px;}
.swarmz-console .swz-card{background:transparent;border:0;border-top:2px solid var(--line2);border-radius:0;padding:12px 0 6px;}
.swarmz-console .swz-card-l{color:var(--dim);font-size:11px;font-weight:650;text-transform:uppercase;letter-spacing:.06em;}
.swarmz-console .swz-card-v{margin-top:7px;font-size:24px;font-weight:650;line-height:1.15;color:var(--ink);font-variant-numeric:tabular-nums;letter-spacing:-.01em;}
.swarmz-console .swz-card-c{margin-top:5px;color:var(--dim);font-size:12px;line-height:1.45;}
.swarmz-console .swz-of{color:var(--dim);font-size:14px;font-weight:400;}
.swarmz-console .swz-tablewrap{overflow-x:auto;border:1px solid var(--line);border-radius:10px;background:#fff;}
.swarmz-console .swz-table{width:100%;border-collapse:collapse;font-size:13px;}
.swarmz-console .swz-table th,.swarmz-console .swz-table td{padding:10px 14px;border-bottom:1px solid #f4f5f7;text-align:left;vertical-align:top;}
.swarmz-console .swz-table thead th{background:#fff;color:var(--dim);font-weight:600;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;border-bottom:1px solid var(--line);}
.swarmz-console .swz-table tbody tr:last-child td{border-bottom:none;}
.swarmz-console .swz-table tbody tr:hover td{background:#fafafb;}
.swarmz-console .swz-num{text-align:right;font-variant-numeric:tabular-nums;}
.swarmz-console .swz-strong{font-weight:600;color:var(--ink);}
.swarmz-console .swz-muted{color:var(--dim);font-size:12px;}
.swarmz-console .swz-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;border:1px solid transparent;}
.swarmz-console .swz-badge-ok{background:#f0fdf4;color:#15803d;border-color:#dcfce7;}
.swarmz-console .swz-badge-warn{background:#fffbeb;color:#b45309;border-color:#fef3c7;}
.swarmz-console .swz-badge-bad{background:#fef2f2;color:#b91c1c;border-color:#fee2e2;}
.swarmz-console .swz-badge-info{background:#fff7ed;color:#c2410c;border-color:#ffedd5;}
.swarmz-console .swz-badge-neutral{background:#f3f4f6;color:#4b5563;border-color:#e5e7eb;}
.swarmz-console .swz-notice{padding:12px 14px;border-radius:10px;margin:12px 0;font-size:13px;line-height:1.5;}
.swarmz-console .swz-note{margin:14px 0 0;padding:0 0 0 12px;border-left:2px solid var(--line2);color:var(--mut);font-size:12.5px;line-height:1.55;max-width:680px;}
.swarmz-console code{background:#f3f4f6;color:#374151;padding:1px 5px;border-radius:4px;font-size:12px;}
/* Appearance page */
.swarmz-console .swz-themes{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;margin:0 0 8px;}
.swarmz-console .swz-theme{position:relative;border:1px solid var(--line2);border-radius:12px;padding:12px;cursor:pointer;background:#fff;transition:border-color .12s ease, box-shadow .12s ease;}
.swarmz-console .swz-theme:hover{border-color:#cfd3da;}
.swarmz-console .swz-theme input{position:absolute;opacity:0;pointer-events:none;}
.swarmz-console .swz-theme.swz-on{border-color:var(--acc);box-shadow:0 0 0 3px rgba(249,115,22,.14);}
.swarmz-console .swz-theme-name{margin:10px 0 1px;font-size:13px;font-weight:650;color:var(--ink);}
.swarmz-console .swz-theme-desc{margin:0;font-size:11.5px;color:var(--dim);line-height:1.45;}
.swarmz-console .swz-prev{height:96px;border-radius:8px;overflow:hidden;position:relative;background:#fafafb;border:1px solid #f0f1f4;}
.swarmz-console .swz-swatches{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0 4px;}
.swarmz-console .swz-swatch{position:relative;width:30px;height:30px;border-radius:50%;cursor:pointer;border:2px solid #fff;box-shadow:0 0 0 1px var(--line2);}
.swarmz-console .swz-swatch input{position:absolute;opacity:0;pointer-events:none;}
.swarmz-console .swz-swatch.swz-on{box-shadow:0 0 0 2px var(--acc);}
.swarmz-console .swz-hex{width:120px;padding:6px 10px;border:1px solid var(--line2);border-radius:8px;font-size:13px;font-family:ui-monospace,Menlo,monospace;}
.swarmz-console .swz-save{display:inline-block;padding:9px 20px;border:0;border-radius:9px;background:var(--acc);color:#fff;font-size:13px;font-weight:650;cursor:pointer;}
.swarmz-console .swz-save:hover{opacity:.9;}
</style>';
    }
}
