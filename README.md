# Swarmz WHMCS Module

The official Swarmz server module for WHMCS. It lets a hosting company resell
Swarmz AI workspaces to its existing WHMCS customer base: WHMCS owns billing
and the customer relationship, while this module turns each WHMCS service
lifecycle event into a single-purpose call to the Swarmz platform API.

It is a thin client — all business logic lives server-side on Swarmz. The
module keeps no state of its own beyond two per-service custom fields
(`Swarmz Tenant ID`, `Swarmz Dashboard URL`).

---

## Capabilities

**Provisioning lifecycle** — each WHMCS service maps to one Swarmz workspace:

| WHMCS event | Swarmz API call | Effect |
|-------------|-----------------|--------|
| Create | `platform-create` | Provisions the customer's workspace; stores its tenant id + dashboard URL on the service |
| Change package | `platform-plan` + `platform-plan-refresh` | Pushes new entitlements (credits, projects, compute, domains, cloud budget) and resets the monthly credit cycle at the boundary |
| Renewal (`InvoicePaid`) | `platform-plan-refresh` | Resets the monthly paid-credit grant and applies rollover for the new cycle |
| Suspend | `platform-suspend` | Pauses pods + cloud, unpublishes sites, blocks SSO |
| Unsuspend | `platform-unsuspend` | Resumes pods + cloud, republishes sites |
| Terminate | `platform-terminate` | Full teardown (permanent) |

All calls are **idempotent** on `external_ref = whmcs:<serviceid>`, so WHMCS
retries never double-provision.

**One-time credit top-ups** — the "Initial credit top-up" product option grants
credits at provision time via `platform-topup` (idempotent per service).

**One-click SSO** — an "Open AI Editor" button in both the client area and the
admin service screen calls `platform-sso` and redirects the user straight
into their workspace — their custom domain when configured, otherwise
`<slug>.swarmz.net`. No Swarmz login prompt.

**Usage reporting** — `platform-usage` feeds credits used, AI USD spend, and
cloud USD spend back into WHMCS for usage-based billing or client-area display.

**Per-product entitlements** — eleven WHMCS product config options on each
product's **Module Settings** tab map 1:1 to the Swarmz entitlement schema.
They're grouped by a `Group · field` label prefix:

- **Credits** — free per day, free monthly cap, paid monthly grant, rollover (none / 1 / 2 cycles), signup bonus.
- **Limit** — projects, published apps, custom domains (count), allow custom domains (on/off). For the count caps, **0 or blank = unlimited**.
- **Compute** — max size (display lock; provisioning is Nano today).
- **Cloud** — budget cap in USD (pauses the backend past that monthly spend).

To offer tiers, create one product per plan and set different values. The
options are positional — never reorder them in the module.

**Connection test** — the WHMCS "Test Connection" button validates the API key
against `platform-sso` with a non-existent tenant id (read-only, no side
effects).

**Admin: Reseller Console** — an activatable WHMCS *addon* module (Setup → Addon
Modules) that gives you one screen showing every customer's plan and their live
credit + cloud usage (your wholesale cost), with a period switcher. It's also
where you set the host-side presentation kept off the Swarmz dashboard — SSO
button label, credit terminology, whether to show AI/cloud spend to clients,
support link — and where you set the API key once (the provisioning module
reuses it automatically).

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
- An active Swarmz platform account with client-workspace provisioning enabled
- A Swarmz API key (`sk_live_…`) issued from the Swarmz admin area

---

## Install & onboarding

Download the packaged module ZIP from the
[releases page](https://github.com/swarmzlabs/whmcs-module/releases/latest) and
unzip it at your WHMCS root. It lays down two modules:

- `<whmcs-root>/modules/servers/swarmz/` — the provisioning module (no
  activation step; selected per-product).
- `<whmcs-root>/modules/addons/swarmz/` — the Reseller Console (activate it
  under **Setup → Addon Modules**, then set your API key in its config).

### Updating an existing install

Overwrite the two module folders with the new version's — the module is
stateless (its only persistence is the two per-service custom fields), so there
is no data migration and no downtime:

```
modules/servers/swarmz/      ← replace
modules/addons/swarmz/        ← replace
```

New product config options (added in a minor release) appear automatically on
each product's **Module Settings** tab after the overwrite; previously-saved
option values are preserved because options are positional and only ever
appended. No WHMCS reactivation is needed.

The Swarmz API reference (every `platform-*` endpoint, request/response shapes,
error codes) is published at **https://docs.swarmz.net/api**.

---

## License

MIT — see `LICENSE`.
