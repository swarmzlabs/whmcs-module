<?php
/**
 * Swarmz Reseller Console — Credit Pack mapping.
 *
 * A "credit pack" is an ordinary WHMCS Product Addon (Setup → Products/Services
 * → Product Addons) that the host sells next to their Swarmz product — e.g.
 * "1,000 Extra Credits" as a one-time purchase, or a recurring monthly boost.
 * The addon needs NO provisioning module: this table maps the addon definition
 * (tbladdons.id) to the number of Swarmz credits it grants, and the server
 * module's InvoicePaid hook posts /platform-topup for every PAID invoice line
 * that references a mapped addon (idempotent per invoice line, so re-fired
 * hooks and the daily sweep can never double-grant).
 *
 * Payment is the grant trigger — activation state is irrelevant. A one-time
 * addon grants once; a recurring addon re-grants on every paid renewal
 * invoice. Top-up credits expire on the platform after 12 months, and the
 * host is metered wholesale for them at assignment (charge-on-assign).
 *
 * Schema is owned by THIS addon module (created on activate/upgrade); the
 * server module's hooks read the table by name only, guarded by hasTable, so
 * an inactive console simply means "no packs mapped".
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Addon\Swarmz;

use WHMCS\Database\Capsule;

class CreditPacks
{
    /** Mapping table: one row per WHMCS addon definition that grants credits. */
    const TABLE = 'mod_swarmz_credit_packs';

    /** Create the mapping table (idempotent; safe on every activate/upgrade). */
    public static function ensureSchema(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, function ($table) {
                    $table->increments('id');
                    // tbladdons.id — the addon DEFINITION (not a client's instance).
                    $table->unsignedInteger('addon_id')->unique();
                    // Whole credits granted per paid invoice line for this addon.
                    $table->unsignedInteger('credits');
                    // Platform pack this mapping mirrors (platform_credit_packs
                    // .code on Swarmz) — null for a hand-typed custom amount.
                    // credits is the CACHE of the pack's amount, refreshed on
                    // console view + daily cron; grants read credits, so a
                    // mapping keeps working even if the platform is briefly
                    // unreachable or the pack is archived.
                    $table->string('pack_code', 40)->nullable();
                    $table->string('pack_name', 100)->nullable();
                    $table->dateTime('created_at');
                    $table->dateTime('updated_at');
                });
                return;
            }
            // Additive upgrades only — the prime invariant says updates never
            // destroy host data, so existing columns are never altered or
            // dropped. v1.19.0 added the platform-pack reference columns.
            if (!$schema->hasColumn(self::TABLE, 'pack_code')) {
                $schema->table(self::TABLE, function ($table) {
                    $table->string('pack_code', 40)->nullable();
                    $table->string('pack_name', 100)->nullable();
                });
            }
        } catch (\Throwable $e) {
            // Schema plumbing is best-effort — a failure surfaces on first use.
        }
    }

    /** True when the mapping table exists (addon activated on ≥ 1.11.0). */
    public static function schemaReady(): bool
    {
        try {
            return Capsule::schema()->hasTable(self::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * All WHMCS product addons with their current credit mapping (0 = unmapped).
     * Used by the Console's Credit Packs page.
     *
     * Visibility semantics (two independent WHMCS flags, easy to conflate):
     *   - `hidden`     — the "Hidden" checkbox. Controls the CLIENT-AREA addon
     *                    store (cart.php?gid=addons), i.e. whether an EXISTING
     *                    customer can buy the pack. This is the flag that
     *                    matters for top-ups.
     *   - `showorder`  — "Show on Order Form". Only controls whether the addon
     *                    is offered during the INITIAL product checkout.
     * `retired` (newer WHMCS) removes it from sale everywhere. Columns are read
     * defensively so older schemas without hidden/retired still work.
     *
     * @return array<int,array{addon_id:int,name:string,billingcycle:string,packages:string,showorder:bool,hidden:bool,retired:bool,credits:int}>
     */
    public static function listAddons(): array
    {
        try {
            $mapped = [];
            if (self::schemaReady()) {
                // Full-row read: pack_code/pack_name may not exist yet on an
                // install whose upgrade hook hasn't run — read them defensively.
                foreach (Capsule::table(self::TABLE)->get() as $r) {
                    $mapped[(int) $r->addon_id] = [
                        'credits'   => (int) $r->credits,
                        'pack_code' => isset($r->pack_code) ? (string) $r->pack_code : '',
                        'pack_name' => isset($r->pack_name) ? (string) $r->pack_name : '',
                    ];
                }
            }
            $out = [];
            $rows = Capsule::table('tbladdons')->orderBy('name')->get();
            foreach ($rows as $r) {
                $id = (int) $r->id;
                $out[] = [
                    'addon_id'     => $id,
                    'name'         => (string) $r->name,
                    'billingcycle' => (string) $r->billingcycle,
                    'packages'     => (string) $r->packages,
                    'showorder'    => ((int) ($r->showorder ?? 0)) === 1,
                    'hidden'       => ((int) ($r->hidden ?? 0)) === 1,
                    'retired'      => ((int) ($r->retired ?? 0)) === 1,
                    'credits'      => $mapped[$id]['credits'] ?? 0,
                    'pack_code'    => $mapped[$id]['pack_code'] ?? '',
                    'pack_name'    => $mapped[$id]['pack_name'] ?? '',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Set (or clear, with 0) the credit amount for an addon definition.
     * Upsert keyed on addon_id; 0 removes the mapping entirely.
     *
     * $packCode links the mapping to a platform pack (platform_credit_packs
     * .code); pass null/'' for a hand-typed custom amount. The grant path
     * forwards the code to /platform-topup so the Swarmz dashboard can count
     * sales per pack.
     */
    public static function set(int $addonId, int $credits, ?string $packCode = null, ?string $packName = null): void
    {
        if ($addonId <= 0 || !self::schemaReady()) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        try {
            if ($credits <= 0) {
                Capsule::table(self::TABLE)->where('addon_id', $addonId)->delete();
                return;
            }
            $values = [
                'credits'    => $credits,
                'pack_code'  => ($packCode !== null && $packCode !== '') ? $packCode : null,
                'pack_name'  => ($packName !== null && $packName !== '') ? $packName : null,
                'updated_at' => $now,
            ];
            try {
                $updated = Capsule::table(self::TABLE)
                    ->where('addon_id', $addonId)
                    ->update($values);
                if ($updated === 0) {
                    Capsule::table(self::TABLE)->insert(
                        ['addon_id' => $addonId, 'created_at' => $now] + $values
                    );
                }
            } catch (\Throwable $e) {
                // Progressive fallback: pack columns missing (upgrade hook not
                // run yet) — save the credits so the mapping still works.
                unset($values['pack_code'], $values['pack_name']);
                $updated = Capsule::table(self::TABLE)
                    ->where('addon_id', $addonId)
                    ->update($values);
                if ($updated === 0) {
                    Capsule::table(self::TABLE)->insert(
                        ['addon_id' => $addonId, 'created_at' => $now] + $values
                    );
                }
            }
        } catch (\Throwable $e) {
            // Surface nothing — the Console re-reads and shows the real state.
        }
    }

    /**
     * Refresh the cached credits/name of every pack-linked mapping from the
     * platform catalog (the plan builder is the source of truth; this table
     * only caches its numbers so grants and the client panel never need a
     * live API call). A pack missing from the catalog — archived or deleted
     * on Swarmz — keeps its last known credits: an already-sold mapping must
     * never silently stop granting.
     *
     * @param array<int,array<string,mixed>> $catalog platform-plans credit_packs rows
     * @return int mappings updated
     */
    public static function refreshFromCatalog(array $catalog): int
    {
        if (empty($catalog) || !self::schemaReady()) {
            return 0;
        }
        $byCode = [];
        foreach ($catalog as $p) {
            $code = (string) ($p['code'] ?? '');
            $credits = (int) ($p['credits'] ?? 0);
            if ($code !== '' && $credits > 0) {
                $byCode[$code] = ['credits' => $credits, 'name' => (string) ($p['name'] ?? '')];
            }
        }
        if (empty($byCode)) {
            return 0;
        }
        $changed = 0;
        try {
            foreach (Capsule::table(self::TABLE)->whereNotNull('pack_code')->get() as $r) {
                $code = (string) ($r->pack_code ?? '');
                if ($code === '' || !isset($byCode[$code])) {
                    continue;
                }
                $cat = $byCode[$code];
                if ((int) $r->credits !== $cat['credits'] || (string) ($r->pack_name ?? '') !== $cat['name']) {
                    Capsule::table(self::TABLE)->where('id', (int) $r->id)->update([
                        'credits'    => $cat['credits'],
                        'pack_name'  => $cat['name'] !== '' ? $cat['name'] : null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $changed++;
                }
            }
        } catch (\Throwable $e) {
            // pack_code column missing — nothing pack-linked to refresh.
        }
        return $changed;
    }
}
