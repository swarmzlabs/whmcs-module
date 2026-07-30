# AGENTS.md — Swarmz WHMCS Module

Rules for anyone (human or AI agent) changing this module. This code runs
inside **partners' production WHMCS installs**. We do not control those
environments, cannot roll them back, and a bad update erases *their* business
data. Treat every change as a change to someone else's production database.

## The prime invariant: updates NEVER destroy host data

An update once wiped a host's settings. That must never happen again. Concretely:

- **No `DROP TABLE`, `TRUNCATE`, or bulk `DELETE` anywhere in the module.**
  Not in `_activate`, not in `_upgrade`, and above all not in `_deactivate`.
  Deactivation is a UI toggle, not an uninstall — `swarmz_deactivate()` must
  keep returning "No customer data or settings were removed" and mean it.
- **Schema changes are additive-only.** `ensureSchema()`-style helpers may
  `CREATE TABLE IF NOT EXISTS` / add columns guarded by `hasTable`/`hasColumn`.
  Never recreate a table to change it; never rename module tables
  (`mod_swarmz_*`) — a rename is a delete from the host's point of view.
- **Never touch WHMCS core tables destructively.** Reads are fine; writes only
  through documented WHMCS APIs (`localAPI`) or narrowly-scoped inserts/updates
  the feature owns (e.g. the module's own custom-field values). Never delete
  rows from `tbl*` tables.
- **Settings live in WHMCS's own storage** (`tbladdonmodules` for the console,
  server/product config for the rest). Upgrades must not clear, re-key, or
  re-default them. If a setting's meaning changes, keep reading the old value.
- **`_upgrade` must be idempotent and lossless.** It may create missing
  tables/columns and backfill; it must never reset mappings, keys, or counters.
  Assume it can run mid-request, twice, or after a partial file sync.
- **File updates are overlay-only.** The install/upgrade story is "unzip over
  the WHMCS root" — the ZIP contains only `modules/servers/swarmz/` and
  `modules/addons/swarmz/`. Never require deleting files or folders, and never
  ship code whose correctness depends on a file being *removed*.

If a change genuinely requires destroying data, it does not ship as an update.
Stop and make it an explicit, documented, admin-confirmed action instead.

## Money-path invariants

- **Grants and charges are idempotent by construction.** Every
  `platform-topup` / plan-change call carries a deterministic idempotency key
  (`whmcs-inv<invoice>-ha<addon>`, `whmcs-ha<addon>-act`). New money paths must
  follow the same pattern — key derived from stable WHMCS row ids, never
  timestamps or random values.
- **Payment is the trigger for invoiced packs; activation for invoice-less
  ones.** Don't add grant paths that can fire for unpaid invoices.
- **Hooks never throw into WHMCS.** Every hook body is wrapped; a Swarmz API
  failure must not break the host's cron, invoicing, or checkout.
- **Cycles never roll early.** Renewal refreshes anchor strictly at the
  service's next-due-date; a blank due date is skipped, never defaulted to
  today.

## WHMCS semantics that have bitten us

- `tbladdons.hidden` (the **Hidden** checkbox) controls the client-area addon
  store; `tbladdons.showorder` ("Show on Order Form") only affects initial
  checkout. Gating store features on `showorder` broke the buy link (v1.11.1).
- Free-cycle addons produce **no invoice** — payment-triggered logic never
  fires for them.
- `AdminLink` renders on the *Servers* config page, not the service view, and
  `sso.php?direct` needs a *client* session — neither works for admin SSO.
- A `$0.00` order can sit Pending forever if the product is set to provision
  "on first payment" — that event never fires for zero-amount orders.
- Read WHMCS columns defensively (`$r->col ?? default`) — schemas differ
  across WHMCS 8.x minors (`hidden`, `retired` are not universal).

## Translations (user-facing text)

Every string a CUSTOMER can see lives in `modules/servers/swarmz/language/`
— `english.php` (the fallback base) plus `german.php`, `french.php`,
`italian.php`, `spanish.php`. Each file returns a flat `key => string` array;
`Helpers::clientLang()` overlays the client's language on English so a missing
key can never blank the UI.

**Rule: when you add or change user-facing text, update ALL five language
files in the same change.** Never hardcode English in a template — add a key.
Strings that embed the host's credit term use a `%s` placeholder substituted
in the template (`{$L.key|replace:'%s':$var}`); format numbers into a variable
with `{assign}` FIRST — chaining `|replace:...|string_format` applies the
modifiers in the wrong order. Admin-console text (the Reseller Console) is
host-facing and stays English.

## Practicalities

- PHP 8.1+ compatible; lint everything with `php -l` (no PHP on dev Macs —
  use `docker run --rm -v "$PWD:/app" php:8.2-cli-alpine`).
- Every outbound call is logged via `logModuleCall` with the bearer key
  redacted; keep it that way for new calls.
- Version bumps: `Api::VERSION` + the addon config `version` move together;
  add a CHANGELOG entry; release ZIPs contain the `modules/` tree only.
- The docs article (swarmz-docs `content/api/whmcs.mdx`) is part of the
  deliverable — behaviour changes ship with a docs update.
