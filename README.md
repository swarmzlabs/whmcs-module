# Swarmz WHMCS Module

The official Swarmz server module for WHMCS. It lets a hosting company resell
Swarmz AI workspaces to its existing WHMCS customer base: WHMCS owns billing
and the customer relationship, while this module turns each WHMCS service
lifecycle event into a single-purpose call to the Swarmz enterprise API.

It is a thin client — all business logic lives server-side on Swarmz. The
module keeps no state of its own beyond two per-service custom fields
(`Swarmz Tenant ID`, `Swarmz Dashboard URL`).

---

## Capabilities

**Provisioning lifecycle** — each WHMCS service maps to one Swarmz workspace:

| WHMCS event | Swarmz API call | Effect |
|-------------|-----------------|--------|
| Create | `enterprise-create` | Provisions the customer's workspace; stores its tenant id + dashboard URL on the service |
| Change package | `enterprise-plan` | Pushes new entitlements (credits, projects, compute, domains, cloud budget) |
| Suspend | `enterprise-suspend` | Pauses pods + cloud, unpublishes sites, blocks SSO |
| Unsuspend | `enterprise-unsuspend` | Resumes pods + cloud, republishes sites |
| Terminate | `enterprise-terminate` | Full teardown (permanent) |

All calls are **idempotent** on `external_ref = whmcs:<serviceid>`, so WHMCS
retries never double-provision.

**One-time credit top-ups** — the "Initial credit top-up" product option grants
credits at provision time via `enterprise-topup` (idempotent per service).

**One-click SSO** — an "Open AI Editor" button in both the client area and the
admin service screen calls `enterprise-sso` and redirects the user straight
into their workspace — their custom domain when configured, otherwise
`<slug>.swarmz.net`. No Swarmz login prompt.

**Usage reporting** — `enterprise-usage` feeds credits used, AI USD spend, and
cloud USD spend back into WHMCS for usage-based billing or client-area display.

**Per-product entitlements** — seven WHMCS product config options map 1:1 to the
Swarmz entitlement schema: credits/day, monthly credit cap, max projects, max
custom domains, max compute size, cloud budget cap, and initial top-up.

**Connection test** — the WHMCS "Test Connection" button validates the API key
against `enterprise-sso` with a non-existent tenant id (read-only, no side
effects).

---

## Authentication

The module talks to one base URL (default `https://api.swarmz.net`) over HTTPS
only (`SSL_VERIFYPEER` on, `VERIFYHOST = 2`). Auth is a `sk_live_…` bearer key
stored in the WHMCS server **Password** field (encrypted at rest by WHMCS). The
key is redacted from every `logModuleCall` entry and never appears in an error
string shown to a client or admin.

---

## Requirements

- WHMCS **8.x or newer**
- PHP **8.1+** with the `curl`, `json`, and `mbstring` extensions
- An active Swarmz enterprise account with client-workspace provisioning enabled
- A Swarmz API key (`sk_live_…`) issued from the Swarmz admin area

---

## Install & onboarding

Download the packaged module ZIP from the
[releases page](https://github.com/swarmzlabs/whmcs-module/releases/latest) and
unzip it at your WHMCS root, so it lands at
`<whmcs-root>/modules/servers/swarmz/`. There is no activation step, database
migration, or addon to enable.

The full hosting-company onboarding guide — enterprise signup, key issuance,
server and product configuration, first customer order, and billing
reconciliation — is published on the Swarmz docs site:

> **Swarmz docs — WHMCS onboarding:** _URL to be added_

---

## License

MIT — see `LICENSE`.
