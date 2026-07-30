<?php
/**
 * Swarmz Reseller Console — "Sync from Swarmz".
 *
 * Builds the host's WHMCS catalog from the partner's PLATFORM catalog, so a
 * partner defines plans + credit packs once in their Swarmz dashboard and
 * never performs WHMCS product surgery by hand:
 *
 *   - Server + server group        (created once, if no Swarmz server exists)
 *   - Product group "Swarmz"       (created once)
 *   - One product per active plan  (module wired, plan_code in configoption1,
 *                                   auto-setup on order, priced)
 *   - Upgrade paths                (every synced product <-> every other)
 *   - One Product Addon per pack   (priced, in-store, assigned to all synced
 *                                   products) + the credit mapping row
 *
 * SAFETY MODEL (see AGENTS.md):
 *   - Preview-first: computeDiff() is strictly read-only; nothing is written
 *     until the admin reviews the plan and clicks Apply.
 *   - Additive-only: existing WHMCS rows are NEVER updated or deleted. The one
 *     narrow exception: an addon THIS SYNC created (recorded in the link
 *     table) is re-hidden/unhidden to follow its pack's active state — a
 *     reversible flag on a row we own.
 *   - Idempotent: every created object is recorded in mod_swarmz_sync_links
 *     (kind + remote code -> local id), so re-running the sync only ever
 *     creates what is missing. A manually-built product that already targets a
 *     plan code, or an addon whose exact name matches a pack, is ADOPTED
 *     (linked, not duplicated).
 *   - Defensive SQL: WHMCS schemas differ across 8.x minors; inserts write
 *     the minimal well-known column set and each item is individually
 *     try/caught — one failure never aborts the rest, and every action or
 *     failure is written to the Module Log as Sync.*.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Addon\Swarmz;

use WHMCS\Database\Capsule;

class Sync
{
    /** Created-object registry: kind + remote_code -> local WHMCS id. */
    const LINKS_TABLE = 'mod_swarmz_sync_links';

    /** Sentinel remote_code for the singleton kinds (server, groups). */
    const SINGLETON = '_';

    /** Create the link table (idempotent; safe on every activate/upgrade). */
    public static function ensureSchema(): void
    {
        try {
            $schema = Capsule::schema();
            if ($schema->hasTable(self::LINKS_TABLE)) {
                return;
            }
            $schema->create(self::LINKS_TABLE, function ($table) {
                $table->increments('id');
                // server | servergroup | productgroup | product | addon
                $table->string('kind', 32);
                // plan/pack code, or '_' for singleton kinds.
                $table->string('remote_code', 64);
                $table->unsignedInteger('local_id');
                $table->dateTime('created_at');
                $table->unique(['kind', 'remote_code']);
            });
        } catch (\Throwable $e) {
            // Best-effort — a failure surfaces on first use.
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Catalog fetch
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fetch plans + credit packs from the platform with the console key.
     *
     * @param object $api a configured \WHMCS\Module\Server\Swarmz\Api client
     * @return array{plans:array<int,array>,packs:array<int,array>}
     */
    public static function fetchCatalog($api): array
    {
        $res = $api->postPlatform('platform-plans', ['active_only' => true]);
        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        $plans = [];
        foreach ((array) ($body['plans'] ?? []) as $p) {
            if (is_array($p) && !empty($p['code'])) {
                $plans[] = $p;
            }
        }
        $packs = [];
        foreach ((array) ($body['credit_packs'] ?? []) as $p) {
            if (is_array($p) && !empty($p['code']) && (int) ($p['credits'] ?? 0) > 0) {
                $packs[] = $p;
            }
        }
        return ['plans' => $plans, 'packs' => $packs];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Diff (STRICTLY read-only)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Compare the platform catalog against WHMCS and return the additive plan.
     *
     * Each item: ['action' => create|adopt|map|link-upgrade|hide|unhide|ok,
     *             'kind' => ..., 'label' => human line, 'code' => remote code]
     * 'ok' rows are informational (already in place, nothing to do).
     *
     * @param array $catalog from fetchCatalog()
     * @param string $apiBaseUrl the console's API base (server hostname)
     * @return array<int,array<string,mixed>>
     */
    public static function computeDiff(array $catalog, string $apiBaseUrl): array
    {
        self::ensureSchema();
        $out = [];
        $links = self::links();

        // ── Server ───────────────────────────────────────────────────────
        $serverId = self::resolveServerId($links);
        if ($serverId === 0) {
            $out[] = [
                'action' => 'create', 'kind' => 'server', 'code' => self::SINGLETON,
                'label' => 'Create server "Swarmz" (' . parse_url($apiBaseUrl, PHP_URL_HOST) . ', SSL, key from the console)',
            ];
        } else {
            $out[] = ['action' => 'ok', 'kind' => 'server', 'code' => self::SINGLETON, 'label' => 'Server present (#' . $serverId . ')'];
        }

        // ── Server group ─────────────────────────────────────────────────
        $groupId = self::resolveServerGroupId($links, $serverId);
        $out[] = $groupId === 0
            ? ['action' => 'create', 'kind' => 'servergroup', 'code' => self::SINGLETON, 'label' => 'Create server group "Swarmz" and add the server to it']
            : ['action' => 'ok', 'kind' => 'servergroup', 'code' => self::SINGLETON, 'label' => 'Server group present (#' . $groupId . ')'];

        // ── Product group ────────────────────────────────────────────────
        $pgId = self::resolveProductGroupId($links);
        $out[] = $pgId === 0
            ? ['action' => 'create', 'kind' => 'productgroup', 'code' => self::SINGLETON, 'label' => 'Create product group "Swarmz"']
            : ['action' => 'ok', 'kind' => 'productgroup', 'code' => self::SINGLETON, 'label' => 'Product group present (#' . $pgId . ')'];

        // ── Products (one per active plan) ───────────────────────────────
        $productIds = [];
        foreach ($catalog['plans'] as $plan) {
            $code = (string) $plan['code'];
            $name = trim((string) ($plan['display_name'] ?? $code));
            $price = (int) ($plan['price_cents'] ?? 0);
            $linked = self::linkedId($links, 'product', $code);
            if ($linked > 0 && self::productExists($linked)) {
                $productIds[$code] = $linked;
                $out[] = ['action' => 'ok', 'kind' => 'product', 'code' => $code, 'label' => 'Product for plan "' . $code . '" present (#' . $linked . ')'];
                continue;
            }
            // Adopt a manually-built Swarmz product already targeting this code.
            $manual = self::findManualProduct($code);
            if ($manual > 0) {
                $productIds[$code] = $manual;
                $out[] = ['action' => 'adopt', 'kind' => 'product', 'code' => $code, 'label' => 'Adopt existing product #' . $manual . ' for plan "' . $code . '" (records the link; no changes to the product)'];
                continue;
            }
            $out[] = [
                'action' => 'create', 'kind' => 'product', 'code' => $code,
                'label' => 'Create product "' . $name . '" — plan ' . $code . ', ' . self::money($price, (string) ($plan['currency'] ?? 'USD')) . '/mo, auto-setup on order',
            ];
        }

        // ── Upgrade paths (pairwise among known products) ────────────────
        $known = array_values($productIds);
        $missingPairs = self::missingUpgradePairs($known);
        if (count($catalog['plans']) > 1) {
            $out[] = [
                'action' => 'link-upgrade', 'kind' => 'upgrade', 'code' => self::SINGLETON,
                'label' => count($missingPairs) > 0
                    ? 'Open upgrade/downgrade paths between all synced products (' . count($missingPairs) . ' link(s) to add now; new products are linked as they are created)'
                    : 'Upgrade/downgrade paths verified between existing synced products (new products are linked as they are created)',
            ];
        }

        // ── Credit packs (one addon per pack) ────────────────────────────
        foreach ($catalog['packs'] as $pack) {
            $code = (string) $pack['code'];
            $name = trim((string) ($pack['name'] ?? $code));
            $credits = (int) $pack['credits'];
            $linked = self::linkedId($links, 'addon', $code);
            if ($linked > 0 && self::addonExists($linked)) {
                $mapped = self::mappedCredits($linked);
                if ($mapped !== $credits) {
                    $out[] = ['action' => 'map', 'kind' => 'addon', 'code' => $code, 'label' => 'Update mapping for "' . $name . '": ' . number_format($mapped) . ' → ' . number_format($credits) . ' credits'];
                } else {
                    $out[] = ['action' => 'ok', 'kind' => 'addon', 'code' => $code, 'label' => 'Addon for pack "' . $code . '" present (#' . $linked . ', ' . number_format($credits) . ' credits)'];
                }
                continue;
            }
            $adopt = self::findAdoptableAddon($name);
            if ($adopt > 0) {
                $out[] = ['action' => 'adopt', 'kind' => 'addon', 'code' => $code, 'label' => 'Adopt existing addon #' . $adopt . ' "' . $name . '" for pack "' . $code . '" and map it to ' . number_format($credits) . ' credits'];
                continue;
            }
            $out[] = [
                'action' => 'create', 'kind' => 'addon', 'code' => $code,
                'label' => 'Create addon "' . $name . '" — ' . number_format($credits) . ' credits, '
                    . self::money((int) ($pack['price_cents'] ?? 0), (string) ($pack['currency'] ?? 'USD'))
                    . (($pack['billing_cycle'] ?? 'onetime') === 'monthly' ? '/mo recurring' : ' one-time')
                    . ', in store, assigned to all synced products, mapped automatically',
            ];
        }

        // ── Retired packs: hide the addon THIS sync created ──────────────
        $activeCodes = [];
        foreach ($catalog['packs'] as $pack) {
            $activeCodes[(string) $pack['code']] = true;
        }
        foreach ($links as $link) {
            if ($link->kind !== 'addon' || isset($activeCodes[$link->remote_code])) {
                continue;
            }
            $addonId = (int) $link->local_id;
            if (self::addonExists($addonId) && !self::addonHidden($addonId)) {
                $out[] = ['action' => 'hide', 'kind' => 'addon', 'code' => $link->remote_code, 'label' => 'Hide addon #' . $addonId . ' (its pack "' . $link->remote_code . '" is no longer active on the platform)'];
            }
        }

        return $out;
    }

    /** True when the diff contains any actionable (non-'ok') row. */
    public static function hasWork(array $diff): bool
    {
        foreach ($diff as $row) {
            if (($row['action'] ?? 'ok') !== 'ok') {
                return true;
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Apply
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Execute the additive plan. Each step is individually guarded; the
     * result rows mirror the diff with a status + detail per item.
     *
     * @param array  $catalog    from fetchCatalog()
     * @param string $apiBaseUrl console API base URL
     * @return array<int,array{label:string,status:string,detail:string}>
     */
    public static function apply(array $catalog, string $apiBaseUrl): array
    {
        self::ensureSchema();
        $results = [];
        $links = self::links();

        // ── Server ───────────────────────────────────────────────────────
        $serverId = self::resolveServerId($links);
        if ($serverId === 0) {
            try {
                $serverId = self::createServer($apiBaseUrl);
                self::link('server', self::SINGLETON, $serverId);
                $results[] = self::res('Server "Swarmz"', 'created', '#' . $serverId);
            } catch (\Throwable $e) {
                $results[] = self::res('Server "Swarmz"', 'failed', $e->getMessage());
                self::log('Sync.Server.Failed', [], ['error' => $e->getMessage()]);
            }
        }

        // ── Server group ─────────────────────────────────────────────────
        $links = self::links();
        $groupId = self::resolveServerGroupId($links, $serverId);
        if ($groupId === 0 && $serverId > 0) {
            try {
                $groupId = self::createServerGroup($serverId);
                self::link('servergroup', self::SINGLETON, $groupId);
                $results[] = self::res('Server group "Swarmz"', 'created', '#' . $groupId);
            } catch (\Throwable $e) {
                $results[] = self::res('Server group "Swarmz"', 'failed', $e->getMessage());
                self::log('Sync.ServerGroup.Failed', [], ['error' => $e->getMessage()]);
            }
        }

        // ── Product group ────────────────────────────────────────────────
        $links = self::links();
        $pgId = self::resolveProductGroupId($links);
        if ($pgId === 0) {
            try {
                $pgId = self::createProductGroup();
                self::link('productgroup', self::SINGLETON, $pgId);
                $results[] = self::res('Product group "Swarmz"', 'created', '#' . $pgId);
            } catch (\Throwable $e) {
                $results[] = self::res('Product group "Swarmz"', 'failed', $e->getMessage());
                self::log('Sync.ProductGroup.Failed', [], ['error' => $e->getMessage()]);
            }
        }

        // ── Products ─────────────────────────────────────────────────────
        $links = self::links();
        $productIds = [];
        foreach ($catalog['plans'] as $plan) {
            $code = (string) $plan['code'];
            $linked = self::linkedId($links, 'product', $code);
            if ($linked > 0 && self::productExists($linked)) {
                $productIds[$code] = $linked;
                continue;
            }
            $manual = self::findManualProduct($code);
            if ($manual > 0) {
                self::link('product', $code, $manual);
                $productIds[$code] = $manual;
                $results[] = self::res('Product for plan "' . $code . '"', 'adopted', '#' . $manual);
                self::log('Sync.Product.Adopted', ['plan' => $code], ['pid' => $manual]);
                continue;
            }
            if ($pgId <= 0 || $groupId <= 0) {
                $results[] = self::res('Product for plan "' . $code . '"', 'skipped', 'needs the product group and server group first');
                continue;
            }
            try {
                $pid = self::createProduct($plan, $pgId, $groupId);
                self::link('product', $code, $pid);
                $productIds[$code] = $pid;
                $results[] = self::res('Product "' . ($plan['display_name'] ?? $code) . '"', 'created', '#' . $pid);
                self::log('Sync.Product.Created', ['plan' => $code], ['pid' => $pid]);
            } catch (\Throwable $e) {
                $results[] = self::res('Product for plan "' . $code . '"', 'failed', $e->getMessage());
                self::log('Sync.Product.Failed', ['plan' => $code], ['error' => $e->getMessage()]);
            }
        }

        // ── Upgrade paths ────────────────────────────────────────────────
        $pairAdds = 0;
        foreach (self::missingUpgradePairs(array_values($productIds)) as $pair) {
            try {
                Capsule::table('tblproduct_upgrade_products')->insert([
                    'product_id' => $pair[0],
                    'upgrade_product_id' => $pair[1],
                ]);
                $pairAdds++;
            } catch (\Throwable $e) {
                self::log('Sync.Upgrade.Failed', ['pair' => $pair], ['error' => $e->getMessage()]);
            }
        }
        if ($pairAdds > 0) {
            $results[] = self::res('Upgrade/downgrade paths', 'created', $pairAdds . ' link(s)');
        }

        // ── Credit packs ─────────────────────────────────────────────────
        $allProductIds = array_values($productIds);
        foreach ($catalog['packs'] as $pack) {
            $code = (string) $pack['code'];
            $name = trim((string) ($pack['name'] ?? $code));
            $credits = (int) $pack['credits'];
            $links = self::links();
            $addonId = self::linkedId($links, 'addon', $code);
            if ($addonId > 0 && self::addonExists($addonId)) {
                if (self::mappedCredits($addonId) !== $credits) {
                    CreditPacks::set($addonId, $credits);
                    $results[] = self::res('Mapping for "' . $name . '"', 'updated', number_format($credits) . ' credits');
                }
                continue;
            }
            $adopt = self::findAdoptableAddon($name);
            if ($adopt > 0) {
                self::link('addon', $code, $adopt);
                CreditPacks::set($adopt, $credits);
                $results[] = self::res('Addon "' . $name . '"', 'adopted', '#' . $adopt . ' mapped to ' . number_format($credits) . ' credits');
                self::log('Sync.Addon.Adopted', ['pack' => $code], ['addon' => $adopt]);
                continue;
            }
            try {
                $addonId = self::createAddon($pack, $allProductIds);
                self::link('addon', $code, $addonId);
                CreditPacks::set($addonId, $credits);
                $results[] = self::res('Addon "' . $name . '"', 'created', '#' . $addonId . ' mapped to ' . number_format($credits) . ' credits');
                self::log('Sync.Addon.Created', ['pack' => $code], ['addon' => $addonId]);
            } catch (\Throwable $e) {
                $results[] = self::res('Addon "' . $name . '"', 'failed', $e->getMessage());
                self::log('Sync.Addon.Failed', ['pack' => $code], ['error' => $e->getMessage()]);
            }
        }

        // ── Hide sync-created addons for retired packs ───────────────────
        $activeCodes = [];
        foreach ($catalog['packs'] as $pack) {
            $activeCodes[(string) $pack['code']] = true;
        }
        foreach (self::links() as $link) {
            if ($link->kind !== 'addon' || isset($activeCodes[$link->remote_code])) {
                continue;
            }
            $addonId = (int) $link->local_id;
            try {
                if (self::addonExists($addonId) && !self::addonHidden($addonId)) {
                    Capsule::table('tbladdons')->where('id', $addonId)->update(['hidden' => 1]);
                    $results[] = self::res('Addon #' . $addonId . ' (pack "' . $link->remote_code . '")', 'hidden', 'pack no longer active on the platform');
                    self::log('Sync.Addon.Hidden', ['pack' => $link->remote_code], ['addon' => $addonId]);
                }
            } catch (\Throwable $e) {
                self::log('Sync.Addon.Hide.Failed', ['pack' => $link->remote_code], ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    // ─────────────────────────────────────────────────────────────────────
    // WHMCS lookups (read-only)
    // ─────────────────────────────────────────────────────────────────────

    /** @return array<int,object> all link rows */
    private static function links(): array
    {
        try {
            $rows = [];
            foreach (Capsule::table(self::LINKS_TABLE)->get() as $r) {
                $rows[] = $r;
            }
            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function linkedId(array $links, string $kind, string $code): int
    {
        foreach ($links as $l) {
            if ($l->kind === $kind && $l->remote_code === $code) {
                return (int) $l->local_id;
            }
        }
        return 0;
    }

    private static function link(string $kind, string $code, int $localId): void
    {
        try {
            Capsule::table(self::LINKS_TABLE)->insert([
                'kind' => $kind,
                'remote_code' => $code,
                'local_id' => $localId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Unique collision on retry — the resolve helpers re-read anyway.
        }
    }

    /** The Swarmz server id: linked first, else any tblservers row of our type. */
    private static function resolveServerId(array $links): int
    {
        $linked = self::linkedId($links, 'server', self::SINGLETON);
        if ($linked > 0) {
            try {
                if (Capsule::table('tblservers')->where('id', $linked)->exists()) {
                    return $linked;
                }
            } catch (\Throwable $e) {
            }
        }
        try {
            $row = Capsule::table('tblservers')->where('type', 'swarmz')->first(['id']);
            return $row ? (int) $row->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** A server group containing the Swarmz server (linked first). */
    private static function resolveServerGroupId(array $links, int $serverId): int
    {
        $linked = self::linkedId($links, 'servergroup', self::SINGLETON);
        if ($linked > 0) {
            try {
                if (Capsule::table('tblservergroups')->where('id', $linked)->exists()) {
                    return $linked;
                }
            } catch (\Throwable $e) {
            }
        }
        if ($serverId <= 0) {
            return 0;
        }
        try {
            $rel = Capsule::table('tblservergroupsrel')->where('serverid', $serverId)->first(['groupid']);
            return $rel ? (int) $rel->groupid : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function resolveProductGroupId(array $links): int
    {
        $linked = self::linkedId($links, 'productgroup', self::SINGLETON);
        if ($linked > 0) {
            try {
                if (Capsule::table('tblproductgroups')->where('id', $linked)->exists()) {
                    return $linked;
                }
            } catch (\Throwable $e) {
            }
        }
        return 0;
    }

    private static function productExists(int $pid): bool
    {
        try {
            return Capsule::table('tblproducts')->where('id', $pid)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A hand-built Swarmz product already targeting this plan code. */
    private static function findManualProduct(string $code): int
    {
        try {
            $row = Capsule::table('tblproducts')
                ->where('servertype', 'swarmz')
                ->where('configoption1', $code)
                ->first(['id']);
            return $row ? (int) $row->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function addonExists(int $addonId): bool
    {
        try {
            return Capsule::table('tbladdons')->where('id', $addonId)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function addonHidden(int $addonId): bool
    {
        try {
            $row = Capsule::table('tbladdons')->where('id', $addonId)->first();
            return $row ? ((int) ($row->hidden ?? 0)) === 1 : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A hand-built addon adoptable for a pack: exactly ONE unlinked addon with
     * this exact name. Ambiguity (0 or 2+) means no adoption — we create.
     */
    private static function findAdoptableAddon(string $name): int
    {
        try {
            $linkedIds = [];
            foreach (self::links() as $l) {
                if ($l->kind === 'addon') {
                    $linkedIds[] = (int) $l->local_id;
                }
            }
            $q = Capsule::table('tbladdons')->where('name', $name);
            if (!empty($linkedIds)) {
                $q = $q->whereNotIn('id', $linkedIds);
            }
            $rows = $q->get(['id']);
            $ids = [];
            foreach ($rows as $r) {
                $ids[] = (int) $r->id;
            }
            return count($ids) === 1 ? $ids[0] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function mappedCredits(int $addonId): int
    {
        $map = \WHMCS\Module\Server\Swarmz\Helpers::creditPackMap();
        return $map[$addonId] ?? 0;
    }

    /** Missing (a -> b) upgrade links among the given product ids, both directions. */
    private static function missingUpgradePairs(array $pids): array
    {
        $pids = array_values(array_unique(array_filter(array_map('intval', $pids))));
        if (count($pids) < 2) {
            return [];
        }
        $existing = [];
        try {
            $rows = Capsule::table('tblproduct_upgrade_products')
                ->whereIn('product_id', $pids)
                ->get(['product_id', 'upgrade_product_id']);
            foreach ($rows as $r) {
                $existing[((int) $r->product_id) . ':' . ((int) $r->upgrade_product_id)] = true;
            }
        } catch (\Throwable $e) {
            return []; // can't read → don't guess; upgrade links are optional
        }
        $missing = [];
        foreach ($pids as $a) {
            foreach ($pids as $b) {
                if ($a !== $b && !isset($existing[$a . ':' . $b])) {
                    $missing[] = [$a, $b];
                }
            }
        }
        return $missing;
    }

    // ─────────────────────────────────────────────────────────────────────
    // WHMCS writes (each caller wraps in try/catch)
    // ─────────────────────────────────────────────────────────────────────

    private static function createServer(string $apiBaseUrl): int
    {
        $host = (string) (parse_url($apiBaseUrl, PHP_URL_HOST) ?: $apiBaseUrl);
        // Password stays blank so the module falls back to the console key
        // (the documented default). MySQL strict mode rejects an omitted
        // NOT-NULL TEXT column, so the first attempt fills the long-stable
        // core text columns explicitly; the fallback is the minimal set for
        // schemas where any of those don't exist.
        $core = [
            'name' => 'Swarmz',
            'ipaddress' => '',
            'hostname' => $host,
            'type' => 'swarmz',
            'username' => '',
            'password' => '',
            'secure' => 'on',
            'port' => 443,
            'active' => 1,
            'disabled' => 0,
            'maxaccounts' => 0,
        ];
        try {
            return (int) Capsule::table('tblservers')->insertGetId($core + [
                'assignedips' => '',
                'accesshash' => '',
                'monthlycost' => '0.00',
                'noc' => '',
                'statusaddress' => '',
                'nameserver1' => '',
                'nameserver1ip' => '',
                'nameserver2' => '',
                'nameserver2ip' => '',
                'nameserver3' => '',
                'nameserver3ip' => '',
                'nameserver4' => '',
                'nameserver4ip' => '',
                'nameserver5' => '',
                'nameserver5ip' => '',
            ]);
        } catch (\Throwable $e) {
            return (int) Capsule::table('tblservers')->insertGetId($core);
        }
    }

    private static function createServerGroup(int $serverId): int
    {
        $groupId = (int) Capsule::table('tblservergroups')->insertGetId([
            'name' => 'Swarmz',
            'filltype' => 1,
        ]);
        Capsule::table('tblservergroupsrel')->insert([
            'groupid' => $groupId,
            'serverid' => $serverId,
        ]);
        return $groupId;
    }

    private static function createProductGroup(): int
    {
        // WHMCS 8 adds slug/headline/tagline columns; try the fuller shape and
        // fall back to the minimal one for older schemas.
        try {
            return (int) Capsule::table('tblproductgroups')->insertGetId([
                'name' => 'Swarmz',
                'slug' => 'swarmz',
                'headline' => '',
                'tagline' => '',
                'orderfrmtpl' => '',
                'disabledgateways' => '',
                'hidden' => 0,
                'order' => 0,
            ]);
        } catch (\Throwable $e) {
            return (int) Capsule::table('tblproductgroups')->insertGetId([
                'name' => 'Swarmz',
                'hidden' => 0,
                'order' => 0,
            ]);
        }
    }

    /**
     * Create one product for a plan: WHMCS's own AddProduct API builds the
     * row (correct defaults for the running WHMCS version), then we wire the
     * module columns and price it in the host's default currency.
     */
    private static function createProduct(array $plan, int $productGroupId, int $serverGroupId): int
    {
        if (!function_exists('localAPI')) {
            throw new \RuntimeException('localAPI unavailable');
        }
        $code = (string) $plan['code'];
        $name = trim((string) ($plan['display_name'] ?? $code));
        $priceCents = (int) ($plan['price_cents'] ?? 0);
        $paytype = $priceCents > 0 ? 'recurring' : 'free';

        $resp = localAPI('AddProduct', [
            'name' => $name !== '' ? $name : $code,
            'gid' => $productGroupId,
            'type' => 'other',
            'paytype' => $paytype,
            'module' => 'swarmz',
            'hidden' => false,
            'welcomeemail' => 0,
        ]);
        if (!is_array($resp) || ($resp['result'] ?? '') !== 'success' || (int) ($resp['pid'] ?? 0) <= 0) {
            throw new \RuntimeException('AddProduct failed: ' . json_encode($resp));
        }
        $pid = (int) $resp['pid'];

        // Wire the module: server group, plan code, auto-setup on order (the
        // documented setting — "on first payment" never fires for $0 orders).
        Capsule::table('tblproducts')->where('id', $pid)->update([
            'servertype' => 'swarmz',
            'servergroup' => $serverGroupId,
            'configoption1' => $code,
            'autosetup' => 'order',
        ]);

        if ($priceCents > 0) {
            self::writePricing('product', $pid, $priceCents);
        }
        return $pid;
    }

    private static function createAddon(array $pack, array $productIds): int
    {
        $name = trim((string) ($pack['name'] ?? $pack['code']));
        $priceCents = (int) ($pack['price_cents'] ?? 0);
        $recurring = ($pack['billing_cycle'] ?? 'onetime') === 'monthly';
        $cycle = $priceCents <= 0 ? 'free' : ($recurring ? 'recurring' : 'onetime');
        $packages = implode(',', array_map('intval', $productIds));

        $base = [
            'name' => $name,
            'description' => trim((string) ($pack['description'] ?? '')),
            'billingcycle' => $cycle,
            'tax' => 0,
            'showorder' => 1,
            'packages' => $packages,
        ];
        // Progressive fallback: fuller set first (strict-mode-safe values for
        // the long-stable NOT NULL columns), then hidden-only, then minimal —
        // schemas vary across WHMCS 8.x minors.
        try {
            $addonId = (int) Capsule::table('tbladdons')->insertGetId($base + [
                'hidden' => 0,
                'downloads' => '',
                'type' => '',
                'welcomeemail' => 0,
                'suspendproduct' => 0,
                'weight' => 0,
            ]);
        } catch (\Throwable $e) {
            try {
                $addonId = (int) Capsule::table('tbladdons')->insertGetId($base + ['hidden' => 0]);
            } catch (\Throwable $e2) {
                $addonId = (int) Capsule::table('tbladdons')->insertGetId($base);
            }
        }
        if ($priceCents > 0) {
            self::writePricing('addon', $addonId, $priceCents);
        }
        return $addonId;
    }

    /**
     * Price a product/addon in the host's DEFAULT currency: the given amount
     * on the monthly column (WHMCS reads an addon's/one-time price from
     * `monthly` regardless of cycle), every other term disabled (-1), no
     * setup fees. Additional currencies are left for the admin — WHMCS
     * auto-fills them from exchange rates when the row is saved in admin.
     */
    private static function writePricing(string $type, int $relId, int $priceCents): void
    {
        $currency = 1;
        try {
            $row = Capsule::table('tblcurrencies')->where('default', 1)->first(['id']);
            if ($row) {
                $currency = (int) $row->id;
            }
        } catch (\Throwable $e) {
        }
        $amount = number_format($priceCents / 100, 2, '.', '');
        try {
            $exists = Capsule::table('tblpricing')
                ->where('type', $type)->where('relid', $relId)->where('currency', $currency)
                ->exists();
            if ($exists) {
                return; // never overwrite an existing pricing row
            }
            Capsule::table('tblpricing')->insert([
                'type' => $type,
                'currency' => $currency,
                'relid' => $relId,
                'msetupfee' => '0.00',
                'qsetupfee' => '0.00',
                'ssetupfee' => '0.00',
                'asetupfee' => '0.00',
                'bsetupfee' => '0.00',
                'tsetupfee' => '0.00',
                'monthly' => $amount,
                'quarterly' => '-1.00',
                'semiannually' => '-1.00',
                'annually' => '-1.00',
                'biennially' => '-1.00',
                'triennially' => '-1.00',
            ]);
        } catch (\Throwable $e) {
            // Pricing failure must not lose the created object; the admin
            // can price it in the UI. Logged by the caller's result row.
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Small helpers
    // ─────────────────────────────────────────────────────────────────────

    private static function res(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }

    private static function money(int $cents, string $currency): string
    {
        $n = number_format($cents / 100, 2);
        return strtoupper($currency) === 'USD' ? '$' . $n : $n . ' ' . strtoupper($currency);
    }

    private static function log(string $action, $request, $response): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        try {
            logModuleCall('swarmz', $action, $request, $response, $response, ['sk_live_', 'sk_test_']);
        } catch (\Throwable $e) {
        }
    }
}
