# Changelog

All notable changes to this WHMCS module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

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
