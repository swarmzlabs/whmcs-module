# Changelog

All notable changes to this WHMCS module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

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
