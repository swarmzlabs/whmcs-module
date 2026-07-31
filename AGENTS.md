# AGENTS.md — Swarmz WHMCS Module

> **This document is public.** This repository — this file included — is read
> by the hosting partners who run the module in production, not just by the
> people building it. Write everything here (and in commit messages, PR
> descriptions, and release notes) for that audience: engineering standards
> and technical facts only. Internal incident reports, customer or partner
> names, timelines, and war stories do not belong anywhere in this repo.

This module runs inside partners' production WHMCS installs. We do not
control those environments and cannot roll them back, so every change is held
to the standards below.

## Data safety: the prime directive

An update — whether applied by the in-admin updater, a ZIP upload, or a
version upgrade hook — must never destroy or degrade host data. Concretely:

- **No `DROP TABLE`, `TRUNCATE`, or bulk `DELETE` anywhere in the module.**
  Not in `_activate`, not in `_upgrade`, and above all not in `_deactivate`.
  Deactivation is a UI toggle, not an uninstall — `swarmz_deactivate()` must
  keep returning "No customer data or settings were removed" and mean it.
- **Schema changes are additive-only.** `ensureSchema()`-style helpers may
  `CREATE TABLE IF NOT EXISTS` / add columns guarded by `hasTable`/`hasColumn`.
  Never recreate a table to change it; never rename or remove module tables
  (`mod_swarmz_*`) — from the host's point of view, a rename is a delete.
  Tables that a later version no longer uses are simply left in place.
- **Never touch WHMCS core tables destructively.** Reads are fine; writes go
  through documented WHMCS APIs (`localAPI`) or narrowly-scoped inserts and
  updates the feature clearly owns. Never delete rows from `tbl*` tables.
- **Settings are sacred.** Configuration lives in WHMCS's own storage
  (`tbladdonmodules`, server/product config). Upgrades must not clear,
  re-key, or re-default any of it. If a setting's meaning changes, keep
  reading the old value.
- **`_upgrade` must be idempotent and lossless.** It may create missing
  tables/columns and backfill; it must never reset mappings, keys, or
  counters. Assume it can run mid-request, twice, or after a partial file
  sync.
- **File updates are overlay-only.** The install and update story is "unzip
  over the WHMCS root" — releases contain only `modules/servers/swarmz/` and
  `modules/addons/swarmz/`. Never require deleting files or folders, and
  never ship code whose correctness depends on a file being *removed*.

If a change genuinely requires destroying data, it does not ship as an
update. It becomes an explicit, documented, admin-confirmed action instead.

## The in-admin updater

`modules/addons/swarmz/lib/Updater.php` installs releases from inside the
Reseller Console. An updater is remote code installation by design, so its
checks are fail-closed and none of them may ever be weakened:

- **Pinned source.** Release metadata comes only from the GitHub API for the
  hard-coded repository constant, over TLS with peer verification. The
  download URL is taken from that API response — never from user input, a
  setting, or any other channel. Do not make the repo configurable.
- **Integrity required.** The downloaded ZIP must match the SHA-256 digest
  GitHub publishes for the release asset. No digest → no update.
- **Path allowlist.** Every archive entry must resolve inside the two module
  directories, with no `..`, no absolute paths, no symlinks followed. One bad
  entry aborts the whole update before any file is touched.
- **Backup before overwrite.** Both live module directories are copied to a
  timestamped backup folder under `modules/` first, and the result screen
  names it. Rollback is "copy the backup back".
- **Additive overlay.** Files are added or overwritten, never deleted — the
  same contract as a manual ZIP upload.
- **Explicit and CSRF-protected.** Updates run only when an admin clicks the
  button; the POST carries the WHMCS admin token. There is no background or
  cron auto-update, deliberately — hosts control when their production
  systems change.
- Version checks are cached (hours, not minutes) and degrade silently: a
  failed or rate-limited check renders nothing, never an error banner.

- **Hand-modified files are never overwritten silently.** Each release
  ships a per-file SHA-256 manifest (`release-manifest.json`); the updater
  diffs the live install against the INSTALLED release's manifest, lists
  every locally changed or deleted file, and refuses to proceed without an
  explicit admin confirmation — enforced server-side, not just in the UI.
  Never bypass or weaken this gate.

Release discipline the updater depends on: every release is a **full
overlay** of both module directories (never a partial/delta ZIP), tagged
`vX.Y.Z`, with exactly one `.zip` asset named `swarmz-whmcs-vX.Y.Z.zip`,
**built with `scripts/build-release.py`** — the script generates the
manifest the hand-modification guard depends on, and the manifest is
committed with the release bump. `Api::VERSION`, the addon config
`version`, and the CHANGELOG move together in the same commit — the
updater compares `Api::VERSION` against the latest tag.

## Money-path invariants

- **Grants and charges are idempotent by construction.** Every
  `platform-topup` / plan-change call carries a deterministic idempotency key
  (`whmcs-inv<invoice>-ha<addon>`, `whmcs-ha<addon>-act`). New money paths
  must follow the same pattern — keys derive from stable WHMCS row ids,
  never from timestamps or random values.
- **Payment is the trigger for invoiced packs; activation for invoice-less
  ones.** Don't add grant paths that can fire for unpaid invoices.
- **The Swarmz pack catalog is the source of truth; the mapping table is a
  cache.** Pack-linked mappings (`pack_code` set) cache the catalog's
  credits so grants and the client panel never need a live API call; the
  cache re-syncs on console view + daily cron. `pack_code` rides along on
  `platform-topup` as attribution only — the `amount` stays authoritative.
  A pack missing from the catalog keeps its last known credits: never
  silently unmap or zero a mapping that customers may still be buying.
- **Hooks never throw into WHMCS.** Every hook body is wrapped; a Swarmz API
  failure must not break the host's cron, invoicing, or checkout.
- **Cycles never roll early.** Renewal refreshes anchor strictly at the
  service's next-due-date; a blank due date is skipped, never defaulted to
  today.
- **Customers never see dollar amounts.** All usage is presented as credits;
  wholesale cost is host-facing only (the Reseller Console).

## WHMCS platform notes

Facts worth knowing before touching related code:

- `tbladdons.hidden` (the **Hidden** checkbox) controls the client-area addon
  store; `tbladdons.showorder` ("Show on Order Form") only affects the
  initial checkout flow. Store-facing features must key off `hidden`.
- Free-cycle addons produce **no invoice** — payment-triggered logic never
  fires for them; use the activation path.
- `AdminLink` renders on the *Servers* config page, not the service view, and
  `sso.php?direct` needs a *client* session — neither is suitable for admin
  SSO.
- A `$0.00` order can sit Pending indefinitely if the product provisions "on
  first payment" — that event never fires for zero-amount orders.
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

- PHP 8.1+ compatible; lint everything with `php -l` (when no local PHP is
  available: `docker run --rm -v "$PWD:/app" php:8.2-cli-alpine`).
- Every outbound call is logged via `logModuleCall` with the bearer key
  redacted; keep it that way for new calls.
- Version bumps: `Api::VERSION` + the addon config `version` move together;
  add a CHANGELOG entry; release ZIPs contain the `modules/` tree only.
- The docs article (swarmz-docs `content/api/whmcs.mdx`) is part of the
  deliverable — behaviour changes ship with a docs update.
