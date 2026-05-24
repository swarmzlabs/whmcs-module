<?php
/**
 * Swarmz Reseller Console — admin dashboard renderer.
 *
 * Joins WHMCS's own service records (which customer, which product/plan, which
 * tenant) to ONE account-wide /enterprise-usage call (live credit + cloud
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

        $out .= $this->renderToolbar($period);
        $out .= $this->renderSummary($services, $usage, $period);
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
     * One account-wide /enterprise-usage call. Returns a tenant_id => usage map
     * plus account totals for the period.
     *
     * @return array{map:array<string,array>,totals:array,period:array}
     */
    private function fetchUsage(string $period): array
    {
        /** @var \WHMCS\Module\Server\Swarmz\Api $api */
        $api = new \WHMCS\Module\Server\Swarmz\Api($this->apiKey, $this->baseUrl);
        $res = $api->postEnterprise('enterprise-usage', ['period' => $period]);
        $usage = (isset($res['body']['usage']) && is_array($res['body']['usage'])) ? $res['body']['usage'] : [];

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

        return [
            'map'    => $map,
            'totals' => [
                'credits' => (float) ($usage['credits_used'] ?? 0),
                'ai'      => (float) ($usage['usd_credits'] ?? 0),
                'cloud'   => (float) ($usage['cloud_usd'] ?? 0),
            ],
            'period' => (isset($usage['period']) && is_array($usage['period'])) ? $usage['period'] : [],
        ];
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
        $test = '<a class="swz-btn" href="' . $this->esc($this->link(['period' => $period, 'swarmz_action' => 'testconn'])) . '">Test connection</a>';

        return '<div class="swz-toolbar"><div class="swz-tabs">' . $btns . '</div><div class="swz-actions">' . $refresh . $test . '</div></div>';
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

    private function renderSummary(array $services, array $usage, string $period): string
    {
        $active = 0;
        foreach ($services as $s) {
            if (strtolower((string) $s->status) === 'active') {
                $active++;
            }
        }
        $t = $usage['totals'];
        $wholesale = $t['ai'] + $t['cloud'];
        $periodLabel = $this->periodLabel($usage['period'], $period);

        $cards = [
            ['Active workspaces', (string) $active, '#2563eb'],
            ['Credits used (' . $periodLabel . ')', number_format($t['credits']), '#7c3aed'],
            ['AI spend', $this->money($t['ai']), '#0891b2'],
            ['Cloud spend', $this->money($t['cloud']), '#ca8a04'],
            ['Wholesale total', $this->money($wholesale), '#16a34a'],
        ];
        $html = '<div class="swz-cards">';
        foreach ($cards as $c) {
            $html .= '<div class="swz-card"><div class="swz-card-v" style="color:' . $c[2] . ';">' . $this->esc($c[1]) . '</div>'
                . '<div class="swz-card-l">' . $this->esc($c[0]) . '</div></div>';
        }
        $html .= '</div>';
        return $html;
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
        $rows = '';
        foreach ($services as $s) {
            $tenant = (string) $s->tenantid;
            $u = isset($map[$tenant]) ? $map[$tenant] : ['credits' => 0, 'ai' => 0, 'cloud' => 0];
            $total = $u['ai'] + $u['cloud'];

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
                . '<td class="swz-num">' . number_format($u['credits']) . '</td>'
                . '<td class="swz-num">' . $this->money($u['ai']) . '</td>'
                . '<td class="swz-num">' . $this->money($u['cloud']) . '</td>'
                . '<td class="swz-num swz-strong">' . $this->money($total) . '</td>'
                . '<td><a class="swz-btn swz-btn-sm" href="' . $this->esc($serviceUrl) . '">Manage</a></td>'
                . '</tr>';
        }

        return '<div class="swz-tablewrap"><table class="swz-table">'
            . '<thead><tr>'
            . '<th>Customer</th><th>Plan</th><th>Status</th><th>Tenant</th>'
            . '<th class="swz-num">Credits</th><th class="swz-num">AI $</th>'
            . '<th class="swz-num">Cloud $</th><th class="swz-num">Wholesale $</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p class="swz-muted" style="margin-top:10px;">Tenant = Swarmz workspace id stored on the service. '
            . 'Credits/spend are for the selected period. A row showing zeros simply had no usage in that period.</p>';
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
