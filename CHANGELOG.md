# Changelog

All notable changes to this WHMCS module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.7.0] - 2026-07-06

Reliability + display pass: a re-minting SSO launcher, a credits-native admin
Reseller Console, and cycle-anchor / dead-code hardening.

### Changed
- **Client-area SSO is now a re-minting custom action.** The "Open AI Editor"
  button no longer uses WHMCS's built-in `&dosinglesignon=1` link (which is
  repeat-suppressed — a second same-tab click on a bfcache-restored product
  page frequently did nothing, made worse by the customer's editor living on a
  different apex than WHMCS via a custom domain). It now POSTs to a module
  custom action (`swarmz_launch`, authorised via `ClientAreaAllowedFunctions`)
  that calls `platform-sso` **fresh on every click** and 302-redirects to the
  returned URL. The button also opens in a **new tab** (`target="_blank"
  rel="noopener"`), so repeat launches reliably land in the editor. The admin "Login as User" button was already `_blank` and
  is unchanged.
- **Admin Reseller Console is now credits-native per workspace.** The
  per-customer table's "AI $" and "Cloud $" columns are replaced by **Build /
  Cloud / AI credit** columns showing `used / grant` this cycle (build includes
  any rollover + top-up still available), sourced from the per-workspace
  `balances.by_workspace[]` the `platform-usage` API already returns. The
  summary cards likewise switch from AI/Cloud spend to per-lane credit cards.
  **One money figure remains — "Wholesale cost"** (AI + cloud $, the host's real
  cost from Swarmz) — as a single table column and summary card, now explicitly
  labelled as wholesale. The "Billing summary" panel (host wholesale cost /
  upcoming invoice) is unchanged.
- `Helpers::resolveCycleAnchor()` **returns `''` instead of today()** when a
  service has no real future due date, and `CreateAccount` now **omits
  `cycle_anchor` entirely** when it is empty (rather than sending a value the
  platform would discard). This aligns CreateAccount with the daily cron's
  stricter "blank due date → skip, never today()" behaviour and removes any risk
  of a "today" anchor rolling a cycle early.

### Removed
- Dead code in `_swarmz_simpleLifecycle`: the `TerminateAccount` "404 is benign"
  branch. `platform-terminate` is idempotent and returns `200 { already: true }`
  for a missing/already-gone tenant — it never returns 404 — so the branch could
  never fire.

### Fixed
- `overview.tpl` header comment corrected: `creditsSource` is `'live' | 'api'`
  (it was documented as `'live' | 'config'`).

### Versions
- `lib/Api.php`: `Api::VERSION` bumped `1.6.0` → `1.7.0` (sent in `User-Agent`).
- Reseller Console addon `version` bumped `1.5.0` → **`1.7.0`** (it had lagged at
  1.5.0 through the 1.6.0 release).

### Note
- **Terminated-workspace reconcile** (`hooks.php` daily status reconcile, which
  suspends a WHMCS service when Swarmz reports the workspace terminated) keys off
  a **410** from `platform-usage`. The deployed `platform-usage` currently
  returns 200 for a terminated tenant, so this reconcile is inert until the
  server side ships 410-for-terminated support. No module change is required when
  it does — the reconcile lights up automatically.
- The customer-visible "plan/credits didn't change after upgrade" report is a
  **server-side** matter (the plan-assignment RPC leaves the legacy
  `workspace_billing.plan_tier`/`plan_id` columns stale, plus 30-day client
  brand/credit snapshot cookies) — **not** a module bug. It is fixed in the
  Swarmz platform, not here.

## [1.6.0] - 2026-07-03

Prorated plan changes. `ChangePackage` no longer rolls the whole billing cycle.

### Changed
- **`ChangePackage` no longer fires a `platform-plan-refresh`.** WHMCS runs
  `ChangePackage` when the **prorated upgrade invoice** is paid — mid-cycle, not
  at a billing boundary. The platform now prorates the change server-side inside
  the plan assignment itself: an upgrade grants `(new − old)` monthly credits ×
  the fraction of the cycle remaining and meters the host for exactly that
  prorated amount; a downgrade applies the new caps immediately and takes full
  effect at renewal (no clawback, matching WHMCS's no-refund default). The old
  behaviour anchored a refresh at the next-due-date, which rolled the **entire**
  cycle — a full new-plan grant plus a **full monthly host charge** for what was
  only a prorated end-customer payment.
- `CreateAccount` / `ChangePackage` send only `plan_code` (+ `external_ref`, and
  `whu` on create). Renewal-boundary refreshes remain owned entirely by
  `hooks.php` (the `InvoicePaid` hook + the daily safety net).

### Removed
- `_swarmz_planRefresh()` from the server module — the mid-cycle refresh call it
  wrapped is gone (renewals are handled by the hooks, proration by the platform).

### Versions
- `lib/Api.php`: `Api::VERSION` bumped `1.5.0` → `1.6.0`.

## [1.5.0] - 2026-06-20

Plan-by-name only. The legacy positional entitlement config options are removed
— a product now provisions purely from a selected named plan.

### BREAKING
- **All legacy positional product config options removed** — `credits_per_day`,
  `monthly_credit_cap`, `max_projects`, `max_custom_domains`, `max_compute_size`,
  `cloud_budget_cap`, `default_credits_topup`, `monthly_credits`,
  `rollover_months`, `max_published_projects`, `custom_domains_enabled`, and
  `plan_name`. The product's Module Settings tab now has a **single** option: the
  **Plan** dropdown. **A Swarmz plan MUST be selected** — `CreateAccount` and
  `ChangePackage` fail with a clear module error ("select a Swarmz plan on the
  product's Module Settings tab") when no plan is chosen.
- **Action required:** re-save each existing Swarmz product and pick a plan from
  the dropdown. Until a product has a plan selected, its provisioning /
  package-change calls will fail with the error above. (Already-provisioned
  workspaces keep running; only new create / change operations need the plan.)

### Changed
- `CreateAccount` / `ChangePackage` send **only** `plan_code` (+ `external_ref`,
  and `whu` on create). The `entitlements{}` fallback is gone; the platform
  resolves the full entitlement set from the plan server-side.
- **Client-area + admin stats now read exclusively from the platform-usage API**
  (`usage` + `balances` + `balances.by_workspace[].caps`). The displayed plan
  caps (custom-domain / published-app limits, cloud budget cap) and the three
  credit pools no longer come from any locally-stored option. The admin Console's
  cloud-spend-vs-cap figure is derived from the per-workspace `cloud_budget_cap`
  cap the API returns, not a config column.
- The initial signup credit top-up is removed (it was tied to the deleted
  `default_credits_topup` option); plans define included credits.
- `lib/Api.php`: `Api::VERSION` bumped to `1.5.0` (sent in the `User-Agent`).
- Reseller Console addon `version` bumped to `1.5.0`.

### Removed
- `Helpers::mapConfigOptionsToEntitlements()` and every positional-option parser
  it used (`getDefaultCreditsTopup`, `parseIntOrNull`, `parseIntOrZero`,
  `parseNumericOrNull`, `parseComputeSize`, `parseRolloverMonths`,
  `resolveCustomDomainsEnabled`, `truthy`, the `ALLOWED_COMPUTE_SIZES` const).
- The admin Console's `serviceModuleConfigOption()` reader (read the deleted
  `configoption6`).

### Note
- Kept unchanged: `external_ref = whmcs:<serviceid>` (provision idempotency key),
  the `tenant_id` workspace identifier on all post-create calls, the named-plan
  fetch (`Api::listPlans` / `platform-plans`), the Reseller Console "Plans" view,
  and the `platform-plan-refresh` cycle roll. `test/smoke.php` now lists plans
  first and provisions with a plan code (skipping the lifecycle when the account
  has no plans).

## [1.4.0] - 2026-06-19

Plan-by-name: provision and change plans by picking a named plan instead of
hand-setting the positional entitlement options.

### Added
- **`platform-plans` API call** (`Api::listPlans()`) — a key-authed `POST {}`
  to `/functions/v1/platform-plans` that returns the reseller account's named
  plans (`code`, `display_name`, `monthly_credits`, `free_credits_per_day`,
  `monthly_credit_cap`, `rollover_months`, `max_projects`,
  `max_published_projects`, `max_custom_domains`, `custom_domains_enabled`,
  `max_compute_size`, `cloud_budget_cap`, `price_cents`, `currency`). Cached per
  Api instance so repeated reads in one request cost a single round-trip.
- **"Plan · named plan" product config option** (position 13 — appended at the
  END; existing positional options 1-12 are unchanged) — a dropdown populated
  live from `platform-plans`. Pick a plan to provision **by name**: its
  `plan_code` is sent to `platform-create` / `platform-plan` and the
  entitlements are resolved server-side, **overriding** options 1-12. Leave it
  on "— None —" to keep using those positional options (the legacy path).
- **`plan_code` on `platform-create` and `platform-plan`** — `CreateAccount`
  and `ChangePackage` now send `plan_code` when a named plan is selected (and
  fall back to the legacy `entitlements{}` mapping when it is not).
- **Reseller Console "Plans" view** — a new page (toolbar → **Plans**) listing
  the account's named plans and their entitlements, with the `code` to drop into
  a product's "Plan" option.

### Changed
- `lib/Api.php`: `Api::VERSION` bumped to `1.4.0` (sent in the `User-Agent`).
- Reseller Console addon `version` bumped to `1.4.0`.

### Note
- This is **not a pure drop-in**: it adds a 13th product config option. Overwrite
  the two module folders as usual; the new "Plan" dropdown appears on each
  product's Module Settings tab after the overwrite, defaulting to "— None —" so
  existing products keep provisioning exactly as before. Options 1-12 retain
  their saved values (still positional, only appended-to). The Plan dropdown and
  the Plans view **degrade gracefully** when `platform-plans` is unreachable or
  undeployed — they show an empty list with a note rather than erroring, and the
  positional options continue to work.

## [1.3.7] - 2026-05-30

Remove the redundant "Open dashboard in new tab" button from the client area.

### Removed
- **"Open dashboard in new tab"** button on the client-area service page. The **"Open AI Editor"** SSO button is the only entry point customers need — this extra link was redundant. Template-only change; the admin Service Details "Open dashboard »" link (admin convenience) is unchanged.

## [1.3.6] - 2026-05-30

Pass a customer-facing plan name through to the white-label dashboard.

### Added
- **"Plan · display name"** product config option (position 12 — existing options are unchanged). Set it to the name your customer should see in their dashboard (e.g. "Starter", "Pro"). It's display-only and flows to the Swarmz platform via `entitlements.plan_name`, where it replaces the generic "Free" label on the customer's workspace + account. Blank = no plan name shown.

### Note
- The customer's name and email (from their WHMCS profile) are already sent on provisioning; the Swarmz platform now persists the email so it appears in the white-label Account section. No action needed — just upgrade.

## [1.3.5] - 2026-05-30

Clearer free-credit limit labels (the daily + monthly free caps are now actually enforced).

### Changed
- **"Credits · free per month (cap)"** (was "free monthly cap") — relabelled and re-described so it's clear this is the lever that caps the *daily* free credits' monthly total (e.g. 5/day up to 30/month free, or 150 for a higher tier). Blank = no monthly limit.
- **"Credits · free per day"** description clarified (resets 00:00 UTC; blank = unlimited per day).

### Note
- These two options now drive **real enforcement** for white-label end-users. Previously the daily allowance was not enforced server-side for reseller workspaces and a blank monthly cap silently behaved like 30; both are fixed on the Swarmz platform (no module change required for the enforcement itself). Set "free per day" + "free per month (cap)" on each plan to match your tiers.

## [1.3.4] - 2026-05-29

Hotfix for a fatal in the 1.3.3 client area.

### Fixed
- **Fatal `TypeError: Unsupported operand types: float - string` rendering the client-area overview.** The new credit template subtracted the daily-used count from the daily allowance, but WHMCS passes product config-option values as strings, and PHP 8 throws on arithmetic with a non-numeric string (a bare `!== null` check does not catch an empty string). The subtraction is now gated on `is_numeric` for both operands. **Template-only change** — if you already deployed 1.3.3, you can recover by replacing just `modules/servers/swarmz/templates/overview.tpl` (the compiled cache refreshes automatically).

## [1.3.3] - 2026-05-29

Client-area credit display fix + cleaner UI, and removal of the admin SSO button.

### Fixed
- **Client area conflated three credit pools into one number** — and could show a confusing "150 credits remaining" when the host had configured, say, 5 free/day + a 1-credit signup top-up (the old code multiplied the daily allowance by 30 to invent a soft monthly ceiling). Credits are now shown as **three separate cards** — **Free** (daily allowance, resets 00:00 UTC, optional monthly cap), **Monthly** (paid grant, renews on the billing cycle, with rollover), and **Top-up** (one-off / purchased) — each with its own remaining balance and reset cadence. The numbers come from the live per-pool balances returned by `platform-usage` (`balances.by_workspace[].{included_*, rollover_*, topup_available, purchased_*, caps}`) when available, and fall back to the configured plan allowances (`credits_per_day`, `monthly_credit_cap`, `monthly_credits`, `default_credits_topup`) otherwise — so the display always matches what the host configured and never fabricates a multiplied total.

### Changed
- **Cleaner, theme-neutral client-area UI.** Replaced the hardcoded light-grey inline card styles with a tidy, responsive card grid that derives its borders/fills from the current text colour, so it reads well on both light and dark WHMCS themes. Still fully unbranded; the SSO button and dashboard link are unchanged.

### Removed
- **Admin "Open AI Editor (admin)" SSO button.** Dropped the `AdminSingleSignOnLabel` metadata entry and the `swarmz_AdminSingleSignOn()` handler. Client-facing SSO (`ServiceSingleSignOnLabel` / `swarmz_ServiceSingleSignOn`) and the admin "Login as User" `AdminLink` button are unaffected.

### Upgrade notes
- Drop-in over 1.3.x — overwrite `modules/servers/swarmz/` and `modules/addons/swarmz/`. No data migration. The richer per-pool credit cards light up automatically once the server's `platform-usage` returns the `balances` section; until then they render from the configured plan allowances.

## [1.3.2] - 2026-05-29

Bug fix: provisioning could send the wrong credential as the API key.

### Fixed
- **`unauthorized: invalid_key` on order accept / CreateAccount when the server Password field was blank.** `resolveApiKey` fell back to `$params['password']`, which in every service lifecycle call is the auto-generated **service** password (e.g. `9-X16]plgY1rJN`), not the `sk_live_` API key — so a blank server Password silently sent garbage and the API rejected it. Removed that fallback entirely; the key now comes only from the server Password field or the Reseller Console addon's "API Key" setting.

### Added
- **Actionable errors instead of `invalid_key`.** `makeApiClient` now validates the resolved key and fails fast with a clear message — "No Swarmz API key configured…" when blank, or "does not look like an API key (expected sk_live_…)" when the value isn't a key. Test Connection surfaces the same guidance, so a misconfigured server is obvious immediately.

## [1.3.1] - 2026-05-28

Reseller plan controls + a clearer product-setup screen.

### Added
- **Four new product config options** (per-plan entitlements):
  - **Credits · paid monthly** (`monthly_credits`) — paid credit grant added each billing cycle.
  - **Credits · rollover** (`rollover_months`) — carry unused paid credits over none / 1 / 2 cycles.
  - **Limit · published apps** (`max_published_projects`) — how many apps can be live at once (0 = unlimited).
  - **Limit · allow custom domains** (`custom_domains_enabled`) — master on/off for custom domains, independent of the count cap.
- **`platform-plan-refresh`** call — fires on renewal (`InvoicePaid`) and package change to reset the monthly credit cycle and apply rollover at the billing boundary.
- **`platform-billing-summary`** call + admin Reseller Console billing cards — credits purchased vs consumed, rollover/balance, cloud spend vs cap (falls back to the key-authed `platform-usage` aggregate when the summary endpoint is owner-JWT only).
- **Daily cron hook** — refreshes usage and reconciles externally-changed tenant status back into WHMCS.
- Client-area panel now shows plan limits (credits remaining, published / custom-domain usage), unbranded.

### Changed
- **Clearer product Module Settings screen.** Config-option labels now use a `Group · field` prefix (Credits / Limit / Compute / Cloud) so related knobs read as clusters, and every description is a single short line. No fields were reordered — saved values on existing products are unaffected.
- Sentinel reconciled across the count caps: **0 or blank = unlimited** for projects, published apps, and custom domains. Disable custom domains entirely with the new on/off option.

### Upgrade notes
- Drop-in over 1.2.x — overwrite `modules/servers/swarmz/` and `modules/addons/swarmz/`. The module is stateless (only two per-service custom fields), so there is no data migration. The four new options appear on each product's **Module Settings** tab after upgrade; existing values for options 1–7 are preserved.

## [1.2.1] - 2026-05-24

Verification release. Re-validated every module API call against the
deployed Swarmz **Platform** edge functions after the Enterprise→Platform
rename — endpoint names, HTTP methods, request fields, the
`Authorization: Bearer sk_live_…` auth, the
`https://api.swarmz.net/functions/v1` base URL, and every response field the
module reads all match the live backend. **No functional changes** — a safe
no-op upgrade from 1.2.0.

### Verified
- Lifecycle (`platform-create`, `platform-suspend`, `platform-unsuspend`,
  `platform-terminate`), plan changes (`platform-plan`), credit top-ups
  (`platform-topup`), usage (`platform-usage` — per-service and the Reseller
  Console account-wide roll-up incl. the `by_workspace` breakdown), and SSO
  (`platform-sso`) all align with the deployed contracts.
- `external_ref = whmcs:<serviceid>` and the `tenant_id` workspace UUID are
  the only tenant identifiers sent; the platform account is resolved
  server-side from the API key (no `platform_account_id` in request bodies).

### Changed
- `lib/Api.php`: bumped `Api::VERSION` to `1.2.1` (sent in `User-Agent`).
- Reseller Console addon `version` bumped to `1.2.1`.

## [1.2.0] - 2026-05-24

Renamed to match the Swarmz **Platform** API. The reselling product
formerly called "Enterprise" is now "Platform", and its API endpoints
were renamed `enterprise-*` → `platform-*`.

### Changed
- All lifecycle, SSO, top-up, and usage calls now target the renamed
  `/functions/v1/platform-*` endpoints (`platform-create`,
  `platform-plan`, `platform-topup`, `platform-suspend`,
  `platform-unsuspend`, `platform-terminate`, `platform-usage`,
  `platform-sso`) across the provisioning (server) module, the Reseller
  Console addon, and the smoke test.
- "Enterprise" branding in module descriptions, config-field help text,
  comments, and `README.md` updated to "Platform" (e.g. "Swarmz platform
  API", "active Swarmz platform account").
- `lib/Api.php`: bumped `Api::VERSION` to `1.2.0` (sent in the
  `User-Agent` header).

Stored data values and WHMCS-internal identifiers are unchanged: the
`Swarmz Tenant ID` / `Swarmz Dashboard URL` custom fields, the
`external_ref = whmcs:<serviceid>` format, and the `swarmz` addon module
name all stay the same.

## [1.0.2] - 2026-05-23

End-to-end verification pass against the live deployed `enterprise-*`
endpoints, running the module's smoke suite against
`https://ashyyneusxtubdhsfpod.supabase.co/functions/v1`. All 20
assertions (every hook + idempotency + suspended-SSO + post-terminate
re-create) pass against the real backend.

### Fixed
- `test/smoke.php`: the `namespace WHMCS\Database { … }` block was
  preceded by a global-scope `define('WHMCS', true);` statement, which
  is a parse error in PHP 8.x. The constant is now defined inside the
  same global `namespace { … }` block as the test runner, and the
  Capsule stub round-trips writes to in-memory tables (`tblcustomfields`,
  `tblcustomfieldsvalues`, `tblhosting`) so the module's tenant-id
  persistence path runs realistically during the smoke test (writes
  done by `CreateAccount` survive into subsequent reads from
  `SuspendAccount`, `UsageUpdate`, etc.).
- `test/smoke.php`: smoke runner now asserts response-shape
  invariants beyond the simple "success" string: tenant_id is stored
  on first create, retried CreateAccount returns the SAME tenant_id,
  usage immediately after create is zero, SSO redirect host matches
  `https://<host>/sso?token=...`, SSO while suspended fails with a
  reason, post-terminate re-create issues a FRESH tenant id.

### Changed
- `README.md`: "Test Connection" section now correctly describes that
  the module probes `/enterprise-sso` with a zero-UUID tenant id (the
  prior wording said it probed `/enterprise-usage`, which was incorrect
  after the v1.0.1 fix).
- `README.md`: the example `UsageUpdate` response now matches the real
  v1.0.1+ shape (`usdCredits`, `periodLabel`, ISO8601 period bounds,
  `projectsCount`/`domainsCount` as `null` sentinels).
- `README.md`: added a "Smoke testing the module" section pointing at
  `test/smoke.php` with the env-var contract.
- `README.md`: documented the "terminate is permanent" contract — a
  re-Create after Terminate provisions a NEW workspace with a new
  tenant id; the previous tenant's data is irretrievably gone.

### Added
- `docs/HOSTING-COMPANY-ONBOARDING.md`: a full 11-section step-by-step
  onboarding guide for hosting providers adopting the module. Covers
  platform signup, key issuance, module install, server +
  product config, customer experience, billing reconciliation
  (wholesale invoicing + dunning), support escalation, white-label
  customisation, and a troubleshooting matrix with the exact error
  strings the module surfaces.

## [1.0.1] - 2026-05-23

Post-deploy contract audit. Verified every WHMCS hook against the live
swarmz `enterprise-*` endpoints and corrected the response-shape drift
that the design-spec build couldn't see.

### Changed
- `swarmz_UsageUpdate` now reads the *actual* live response keys
  (`usage.credits_used`, `usage.usd_credits`, `usage.cloud_usd`,
  `usage.period.{from,to,label}`) instead of the spec-imagined
  `credits_limit` / `projects_count` / `domains_count` / `period_start` /
  `period_end` fields that don't exist server-side. `creditsLimit` is now
  sourced from the WHMCS product config (`monthly_credit_cap`, or
  `credits_per_day * 30` as a soft fallback), where it belongs.
- `swarmz_UsageUpdate` no longer hits the API when the service has no
  `tenant_id` yet — the endpoint resolves only by `tenant_id` and would
  otherwise leak the account-wide aggregate to a pre-provisioned service.
- `swarmz_UsageUpdate` treats `usage_read_failed` (a known server-side
  view-migration soft failure) as a `metrics_unavailable` outcome instead
  of a hard error, so the client area renders the SSO button + budget
  even while metrics are catching up.
- `swarmz_TestConnection` no longer probes `/enterprise-usage` (which
  currently 500s on a server-side view bug). Instead it calls
  `/enterprise-sso` with a known-bad tenant UUID — a 4xx non-`unauthorized`
  reply proves the bearer key is valid without any side effect.
- Client-area overview template now shows the AI USD spend for the period
  instead of an always-zero `projectsCount` (the live endpoint does not
  return a project count).

### Added
- `test/smoke.php` — a self-contained PHP smoke runner that exercises
  every WHMCS hook against the real swarmz API. Stubs the minimum WHMCS
  surface (Capsule facade, `logModuleCall`, `WHMCS` constant) so it runs
  outside a real WHMCS install.

## [1.0.0] - 2026-05-22

Initial release.

### Added
- `swarmz_MetaData` with `DisplayName`, `APIVersion 1.1`, `RequiresServer`, and
  `ServiceSingleSignOnLabel = "Open AI Editor"`.
- `swarmz_ConfigOptions` exposing the seven per-product knobs that map 1:1
  to the Swarmz entitlements schema:
  `credits_per_day`, `monthly_credit_cap`, `max_projects`,
  `max_custom_domains`, `max_compute_size`, `cloud_budget_cap`,
  `default_credits_topup`.
- Provisioning lifecycle: `CreateAccount`, `SuspendAccount`,
  `UnsuspendAccount`, `TerminateAccount`, `ChangePackage`. All call the
  single-purpose `/functions/v1/enterprise-*` endpoints over Bearer auth and
  are idempotent on `external_ref = "whmcs:<serviceid>"`.
- `swarmz_ServiceSingleSignOn` + `swarmz_AdminSingleSignOn` calling
  `/enterprise-sso` and returning the WHMCS-shaped
  `{success, redirectTo, errorMsg}` array.
- `swarmz_UsageUpdate` calling `/enterprise-usage` and surfacing
  credits/cloud/projects.
- `swarmz_AdminServicesTabFields` rendering the tenant id + dashboard link
  on the admin service detail screen.
- `swarmz_AdminLink` exposing an "Open AI Editor" button in the admin area.
- `swarmz_ClientArea` rendering the client-facing overview template
  (`templates/overview.tpl`).
- `swarmz_TestConnection` enabling WHMCS's server "Test Connection" button.
- `lib/Api.php` — small Bearer-authed cURL client with 30s timeout,
  `User-Agent: swarmz-whmcs/1.0`, JSON encode/decode, and `{error, reason}`
  parsing into `SwarmzApiException`.
- `lib/Helpers.php` — config-options → entitlements mapping, base URL +
  API key resolution from `$params['serverhostname']` / `serverpassword`,
  custom-field auto-creation for `Swarmz Tenant ID` and `Swarmz Dashboard URL`.
- `lib/Exceptions.php` — typed exceptions (`SwarmzConfigException`,
  `SwarmzTransportException`, `SwarmzApiException`).
- WHMCS module log integration via `logModuleCall` with the bearer key
  redacted in `$replaceVars`.
- English language file under `language/english.php`.
- Module logo (24×24) and Smarty client-area template.
- README with installation, configuration, security notes, and troubleshooting.
