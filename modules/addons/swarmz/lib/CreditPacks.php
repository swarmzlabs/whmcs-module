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
            if ($schema->hasTable(self::TABLE)) {
                return;
            }
            $schema->create(self::TABLE, function ($table) {
                $table->increments('id');
                // tbladdons.id — the addon DEFINITION (not a client's instance).
                $table->unsignedInteger('addon_id')->unique();
                // Whole credits granted per paid invoice line for this addon.
                $table->unsignedInteger('credits');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
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
                foreach (Capsule::table(self::TABLE)->get(['addon_id', 'credits']) as $r) {
                    $mapped[(int) $r->addon_id] = (int) $r->credits;
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
                    'credits'      => $mapped[$id] ?? 0,
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
     */
    public static function set(int $addonId, int $credits): void
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
            $updated = Capsule::table(self::TABLE)
                ->where('addon_id', $addonId)
                ->update(['credits' => $credits, 'updated_at' => $now]);
            if ($updated === 0) {
                Capsule::table(self::TABLE)->insert([
                    'addon_id'   => $addonId,
                    'credits'    => $credits,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // Surface nothing — the Console re-reads and shows the real state.
        }
    }
}
