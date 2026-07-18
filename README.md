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

**One-click SSO** — an "Open AI Editor" button in both the client area and the
admin service screen calls `platform-sso` fresh on every click and redirects
the user straight into their workspace — the host's verified custom editor
domain when configured, otherwise the platform apex. No Swarmz login prompt.
Transient network/gateway blips are retried automatically (1.9.0), and any
refusal (suspended, cancelled, still provisioning) surfaces as a
plain-language, unbranded message.

**Usage reporting** — `platform-usage` feeds credits used, AI USD spend, and
cloud USD spend back into WHMCS for usage-based billing or client-area display.

### Product setup — pick a plan

Each product's **Module Settings** tab has a single config option: the **Plan**
dropdown. A product provisions by **plan name** — you define your plans once in
your Swarmz admin area, and each plan bundles a complete set of entitlements
(credits, project / domain / published-app limits, compute, cloud budget cap)
behind a stable `code`, with a wholesale `price` for reference.

- The **Plan** dropdown is populated live from your account's plans via the
  key-authed `platform-plans` API. Pick a plan and the module sends its
  `plan_code` to `platform-create` (on provision) and `platform-plan` (on
  package change); the platform resolves the full entitlement set server-side.
- **A plan must be selected.** With the dropdown left on **"— Select a plan —"**,
  provisioning and package-change fail with a clear error ("select a Swarmz plan
  on the product's Module Settings tab") — the module never builds a workspace
  without a plan.
- To offer tiers, create one WHMCS product per plan and point each at a different
  plan in the dropdown.
- The Reseller Console addon has a **Plans** view (toolbar → *Plans*) that lists
  every named plan with its `code` and entitlements, so you can see what each
  plan grants and which to choose.
- **If no plans load** (the `platform-plans` endpoint isn't deployed yet, or the
  API key isn't configured), the dropdown shows only the "— Select a plan —"
  entry plus a short note explaining why — the product-config screen never
  errors. Define your plans and set your key, then reopen the tab.

> **Upgrading from 1.4.x or earlier:** the legacy positional entitlement options
> (free per day, monthly cap, max projects, etc.) were removed in 1.5.0.
> Re-save each existing Swarmz product and pick a plan from the dropdown — see
> the [CHANGELOG](CHANGELOG.md) for the full breaking-change note.

**Prompt Box** — an embeddable widget for the host's own storefront (plain
HTML, WordPress, any builder — one `<script>` tag). Visitors describe the app
they want, optionally pick a plan inline, and land in the WHMCS cart with the
prompt riding along as an opaque token. When the order provisions, the module
passes it to `platform-create` as `initial_prompt` and the customer's first
login opens the editor with that app **already building**. Grab the embed code
(snippet builder + live preview + captured-prompts log) from the Reseller
Console's **Prompt Box** view:

```html
<script src="https://YOUR-WHMCS/modules/addons/swarmz/promptbox.php?a=js"
        data-pid="12"
        data-button="Start building"
        data-placeholder="Describe the app you want to build…"
        data-theme="auto"
        data-accent="#4f46e5"
        async></script>
```

Add `data-plans='[{"pid":12,"label":"Starter","price":"$9/mo"},{"pid":13,"label":"Pro","price":"$29/mo"}]'`
to offer several plans inline; point different entries at different WHMCS
products. A `$0.00` product with instant activation gives the "type a prompt →
free instance spins up building it" flow end-to-end.

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

After the overwrite, each product's **Module Settings** tab shows the single
**Plan** dropdown. No WHMCS reactivation is needed.

> **Upgrading to 1.5.0** is a breaking change: the legacy positional entitlement
> options were removed and a plan must now be selected per product. Re-save each
> existing Swarmz product and pick a plan, or its next provision / package-change
> will fail with a clear "select a Swarmz plan" error. Already-provisioned
> workspaces keep running. See the [CHANGELOG](CHANGELOG.md).

The Swarmz API reference (every `platform-*` endpoint, request/response shapes,
error codes) is published at **https://docs.swarmz.net/api**.

---

## License

MIT — see `LICENSE`.
