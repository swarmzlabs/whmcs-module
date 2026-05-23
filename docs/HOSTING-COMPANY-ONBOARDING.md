# Hosting Company Onboarding Guide

A step-by-step playbook for hosting providers adopting the Swarmz WHMCS
module. Follow it once, end-to-end, and you'll be selling Swarmz AI
workspaces to your existing WHMCS customer base without writing a line
of code.

> **Audience:** WHMCS administrators at a hosting company (shared
> hosting, VPS, reseller, white-label) who want to add a new
> "AI workspace" product to their catalogue and resell Swarmz at a
> margin. Time to first customer: \~30 minutes.

---

## 1. Sign up for Swarmz Enterprise

1. Visit **<https://swarmz.net/enterprise>** and click **Apply**.
2. Fill in the lead form (company name, expected monthly customers,
   target retail price). Submission goes to the Swarmz partnerships
   inbox.
3. A Swarmz account manager will respond within 1 business day with:
   - A **contract**: monthly management fee (typically $50–$500
     depending on volume), per-credit wholesale rate (USD/credit), and
     a cloud margin multiplier.
   - A **white-label option**: include your branding (logo, colours,
     custom domain) for an additional fee.
4. Sign the contract via DocuSign. Swarmz then activates your
   **enterprise account** in the production system.

> **What "enterprise account" means:** it's the wholesale container
> that holds your contract, your API key, and the (eventual) list of
> workspaces you've provisioned for end customers. One enterprise
> account = one hosting provider.

---

## 2. Get your API key

After contract activation, Swarmz emails you:

- One API key in the format `sk_live_<48-char-hex>` (60 chars total,
  e.g. `sk_live_abc123…`). It's shown **once**; if you lose it,
  request a fresh one through the Swarmz admin portal — the old one
  keeps working until you revoke it.
- The API base URL: `https://api.swarmz.net` (default; can also point
  at a private edge if your contract includes one).
- A link to your Swarmz admin dashboard for billing and usage
  reporting.

Store the key in your password manager — **never** commit it to git,
paste it into a ticket, or expose it client-side. The key authenticates
ALL the operations this module performs.

---

## 3. Install the WHMCS module

1. **Download** the module from the private repo:
   <https://github.com/swarmzlabs/whmcs-module> (request access from
   your account manager if you can't see it).

2. **Copy** the `modules/servers/swarmz` directory into your WHMCS
   install:

   ```
   <whmcs-root>/modules/servers/swarmz/
   ```

   The final layout should look like:

   ```
   <whmcs-root>/modules/servers/swarmz/swarmz.php
   <whmcs-root>/modules/servers/swarmz/logo.png
   <whmcs-root>/modules/servers/swarmz/hooks.php
   <whmcs-root>/modules/servers/swarmz/lib/Api.php
   <whmcs-root>/modules/servers/swarmz/lib/Helpers.php
   <whmcs-root>/modules/servers/swarmz/lib/Exceptions.php
   <whmcs-root>/modules/servers/swarmz/language/english.php
   <whmcs-root>/modules/servers/swarmz/templates/overview.tpl
   ```

3. **Permissions**: files 644, directories 755.

   ```bash
   find <whmcs-root>/modules/servers/swarmz -type d -exec chmod 755 {} \;
   find <whmcs-root>/modules/servers/swarmz -type f -exec chmod 644 {} \;
   ```

4. **No activation step** — WHMCS picks up the module automatically
   the next time you visit the Servers form.

> Tip: keep the `test/` directory and `CHANGELOG.md` OUTSIDE the WHMCS
> install (they're under the repo root but not under `modules/`).
> Production WHMCS only needs the `modules/servers/swarmz/` subtree.

---

## 4. Configure the WHMCS server

1. In WHMCS admin: **System Settings → Servers → Add New Server**.
2. Fill in the fields exactly as below. Anything not listed can be
   left at its default.

   | Field | Value | Notes |
   |-------|-------|-------|
   | **Name** | `Swarmz` | Free text — shows in the dropdown when you create products. |
   | **Hostname** | `api.swarmz.net` | The Swarmz API endpoint. Or use the URL Swarmz emailed you. |
   | **IP Address** | leave blank | Not used by this module. |
   | **Monthly Cost** | `0` | The module reports usage, you bill your customers. WHMCS server cost isn't relevant. |
   | **Status Address** | leave blank | |
   | **Status URL** | leave blank | |
   | **Module** | `Swarmz` | Select from the dropdown. |
   | **Username** | `swarmz` | Free text — the module only reads the password. |
   | **Password** | paste your `sk_live_…` key here | WHMCS encrypts this in `tblservers.password` at rest. |
   | **Secure** | leave checked | Forces HTTPS. |
   | **Access Hash** | leave blank | Not used. |
   | **Type** | leave blank (or "Other") | |

3. Click **Test Connection**.

   - **Green ✓** = the key is valid and the API is reachable. Save.
   - **Red ✗** = read the error message. Common ones:
     - `unauthorized: invalid_key` — wrong key. Re-paste it.
     - `Network error talking to swarmz API` — firewall blocking
       outbound HTTPS on port 443, or DNS failure. Whitelist
       `api.swarmz.net`.

4. Save the server. WHMCS will store it under **System Settings →
   Servers** with a green-circle indicator next to it.

> **Server Group**: Most installs put the Swarmz server into its own
> group, named e.g. "AI Workspaces". Go to **Servers → Server Groups →
> Create New Group**, name it, add the Swarmz server, save.

---

## 5. Create your retail product

The bridge between a WHMCS product and a Swarmz workspace is
configured through **product config options 1–7**. Each maps directly
to one of Swarmz's seven entitlements.

### 5.1 Create the product

1. **System Settings → Products/Services → Products/Services → Create
   a New Product**.
2. **Product Type**: `Hosting Account` (this gives you the
   create/suspend/terminate lifecycle hooks). `Other` works too but
   loses some of the WHMCS UI niceties.
3. **Product Group**: pick or create one (e.g. "AI Workspaces").
4. **Product Name**: something like *"Swarmz Starter"*, *"Pro AI"*,
   etc.
5. Save and edit. You'll be taken to the **Details** tab.

### 5.2 Module Settings tab

1. **Module Name**: `Swarmz`.
2. **Server Group**: the group containing your Swarmz server.
3. The seven config options appear. Fill them in:

   | # | Field | Suggested value | What it does |
   |---|-------|-----------------|--------------|
   | 1 | Credits per day | `5` | Daily AI credit budget. Empty = unlimited. |
   | 2 | Monthly credit cap | `150` | Optional monthly ceiling. Empty = no hard cap. |
   | 3 | Max projects | `10` | Project count limit. Empty = unlimited. |
   | 4 | Max custom domains | `1` | Each custom domain costs ~$0.10/mo on Swarmz's side. |
   | 5 | Max compute size | `nano` | Locks the editor's compute selector. Provisioning still uses scale-to-zero. |
   | 6 | Cloud budget cap (USD) | `10` | Optional per-workspace USD ceiling for the customer's cloud spend. |
   | 7 | Initial credit top-up | `0` | One-time credit grant at provisioning. Useful for "free trial" SKUs. |

4. **Automatically setup the product**: tick *"as soon as an order is
   placed"* OR *"after first payment"* — your call. Either way, when
   WHMCS marks the service active, `swarmz_CreateAccount` fires and
   the workspace is provisioned within a second or two.

### 5.3 Pricing tab

1. Set up your retail pricing under the **Pricing** tab. Examples:
   - **Monthly recurring**: $29/mo for 150 credits and 1 project.
   - **Quarterly**: $79/mo (≈9% discount).
   - **Annual**: $290 (≈17% discount).
2. Toggle currencies as needed. WHMCS handles tax and invoice
   generation on your side.

### 5.4 Save & verify

1. Click **Save Changes**.
2. The product is now live in your client area. Customers can order it.
3. The first time a service is activated, the module will also
   auto-create two admin-only product custom fields on this product:
   - `Swarmz Tenant ID`
   - `Swarmz Dashboard URL`

   You'll see them on the admin service page once a service is
   provisioned.

---

## 6. First customer order

Walk through this with a test customer (or your own admin account) to
verify end-to-end:

1. A customer signs up and orders the *"Swarmz Starter"* product.
   WHMCS creates the invoice.
2. The customer pays.
3. WHMCS marks the service `Active` and fires
   `swarmz_CreateAccount(...)`.
4. The module POSTs to `https://api.swarmz.net/functions/v1/enterprise-create`:

   ```json
   {
     "external_ref": "whmcs:<serviceid>",
     "whu": { "email": "<customer-email>", "name": "<customer-name>" },
     "entitlements": { "credits_per_day": 5, "monthly_credit_cap": 150, "max_projects": 10, "max_custom_domains": 1, "max_compute_size": "nano", "cloud_budget_cap": 10 }
   }
   ```

5. Swarmz creates the workspace, returns
   `{ ok: true, tenant_id: "...", dashboard_url: "..." }` in ~500 ms.
6. The module stores those onto the service custom fields. You can see
   them on the admin service page (Custom Fields section).
7. If you configured an initial top-up (option 7 > 0), the module
   immediately POSTs to `/enterprise-topup` to credit the new tenant.

What you see in WHMCS admin:

- The service status is **Active**.
- The admin "Service Details" tab shows a small "Swarmz" panel with the
  tenant id and a "Open dashboard" link to the Swarmz dashboard.
- The admin-side "Open AI Editor" button (added by the module) lets
  you SSO into the customer's workspace as them — useful for support.

---

## 7. Customer experience

When the customer logs into the WHMCS client area and clicks their
service:

1. They land on the service-detail page. The Swarmz client-area panel
   (rendered by `templates/overview.tpl`) shows:
   - A large **Open AI Editor →** button.
   - Four stat cards: credits used (vs limit), AI spend USD this month,
     cloud spend USD this month, and a secondary "Open dashboard"
     button.
2. They click **Open AI Editor**. WHMCS fires
   `swarmz_ServiceSingleSignOn(...)`, which calls
   `/enterprise-sso`. The endpoint mints a short-lived JWT and returns a
   `redirectTo` URL pointing at:
   - The enterprise's custom domain (if one is set on the Swarmz
     account) — e.g. `https://ai.your-hosting.com/sso?token=…`
   - Otherwise the default `<slug>.swarmz.app` subdomain.
3. The browser follows the redirect; the Swarmz dashboard validates
   the token and signs the user into THEIR workspace. They never see
   a Swarmz login page.

The whole hop, button-click to logged-in editor, takes ~1 second.

---

## 8. Billing reconciliation

Swarmz bills the **hosting company** (you), not the end customer.

### Your wholesale invoice

- Every month, Swarmz issues you a single invoice that includes:
  - The flat **management fee** (per your contract).
  - **Per-workspace credit usage** × your wholesale rate.
  - **Cloud usage** with the contract's margin multiplier applied.
  - Custom domain charges (~$0.10 each / month).
- Pay the invoice through Stripe (link in the email and in your
  Swarmz admin dashboard).
- The Swarmz admin has a real-time view of accrued spend so you can
  reconcile against your retail revenue ahead of the next bill.

### Dunning / payment failure

If your wholesale invoice fails (card declined, etc.):

1. **Day 1**: Swarmz auto-retries the card; sends you an email
   notification.
2. **Day 3**: second retry + a warning email.
3. **Day 7**: account moves to `payment_failed` state. Customer
   workspaces continue to function but new provisioning is paused.
4. **Day 14**: all your workspaces are **suspended** (customers see
   the suspended-account message). Your WHMCS-side billing keeps
   running — you just can't provision new workspaces.
5. **Day 30**: hard-terminate threat. Resolve with your Swarmz
   account manager before this point.

### Your retail invoicing (separate)

- WHMCS continues to invoice your customers on whatever pricing
  schedule you set. Swarmz doesn't see those invoices.
- If a CUSTOMER's WHMCS invoice fails, WHMCS triggers
  `swarmz_SuspendAccount` on their service per its dunning settings.
  When they pay, WHMCS fires `swarmz_UnsuspendAccount` and the
  workspace resumes.

### Usage metrics in WHMCS

WHMCS calls `swarmz_UsageUpdate` on a schedule. You can also trigger
it manually from the admin service page. The module surfaces
`creditsUsed`, `cloudUsd`, `usdCredits` (AI spend in USD), and the
period bounds. Wire these into **System Settings → Products/Services
→ Configure Usage Metrics** if you want overage billing on top of
your monthly plan.

---

## 9. Support escalation

| Symptom | First-line check | Who to contact |
|---------|------------------|----------------|
| Customer's "Open AI Editor" button returns `Swarmz: SSO endpoint did not return a redirectTo URL` | Re-trigger SSO once; check **Utilities → Logs → Module Log** for the response body. | Swarmz support: `support@swarmz.net` |
| `unauthorized: invalid_key` on every action | Key revoked or wrong. Re-issue from Swarmz admin → Settings → API Keys. | Self-serve in Swarmz admin |
| `tenant_not_found` after a provision succeeded earlier | The workspace was deleted on the Swarmz side. Re-run **Module Commands → Create** from the admin service page. | Swarmz support |
| All actions timing out (curl errno 28) | Outbound firewall blocking. Whitelist `*.swarmz.net` and `*.supabase.co`. | Your network team |
| Stripe failed for the wholesale invoice | Update card in Swarmz admin → Billing → Payment Method. | Self-serve in Swarmz admin |
| Workspace performance issues, slow editor | Not module-level. Open a ticket with the failing project URL. | Swarmz support |
| WHMCS module log shows `[redacted]` in errors | Working as intended — bearer key is scrubbed from all error strings. | n/a |

**Email**: `support@swarmz.net`
**Slack** (paid plans): your dedicated channel — link in the welcome
email
**Status page**: <https://status.swarmz.net>

---

## 10. Brand customization (white-label)

If your contract includes white-label:

1. **Custom domain**: ask Swarmz to set up `ai.your-hosting.com` (or
   any subdomain you choose) pointing at the Swarmz infrastructure.
   You'll be sent a DNS record set to add at your registrar. Once it
   resolves, the SSO `redirectTo` URLs and dashboard links switch to
   your domain automatically — no module change needed.

2. **Theme**: send Swarmz your brand assets:
   - Logo (SVG preferred, with a transparent background).
   - Primary brand colour (hex).
   - Optional secondary colour, dark-mode palette overrides.

   Swarmz configures the theme on the enterprise account; takes
   effect within ~5 minutes (CDN cache).

3. **Email "from" address**: by default, customer-facing emails (e.g.
   workspace-created confirmations) come from `noreply@swarmz.net`.
   Configure a custom sender (e.g. `ai@your-hosting.com`) via Swarmz
   admin → Settings → Branding. DKIM/SPF setup required.

4. **Documentation rewrite**: white-label contracts include a small
   set of doc pages (getting started, FAQ) that you can host under
   your domain with your brand voice. Swarmz provides the source
   markdown — you re-skin and host.

---

## 11. Troubleshooting

### Error messages from the module

The module returns one of two shapes from each hook:

- **Lifecycle hooks** (`CreateAccount`, `Suspend`, `Unsuspend`,
  `Terminate`, `ChangePackage`): the string `"success"` on success,
  or a human-readable error string on failure. WHMCS displays the
  error string in the **Module Commands** dialog and writes it to the
  Module Log.
- **`ServiceSingleSignOn` / `AdminSingleSignOn`**:
  `[ "success" => true|false, "redirectTo" => ..., "errorMsg" => ... ]`.
- **`UsageUpdate`**: a key-value array with `success` plus usage
  fields, or `success => false` and an `errorMsg`.

### Common error strings + fixes

| Error string | Meaning | Fix |
|--------------|---------|-----|
| `Swarmz API key is missing. Set the sk_live_… key in the WHMCS server Password field.` | Server Password is blank. | Edit the server, paste the key, Save. |
| `unauthorized: invalid_key` | Key wrong or revoked. | Re-issue in Swarmz admin → API Keys, update WHMCS server password. |
| `unauthorized: missing_bearer` | The Authorization header didn't make it. Usually a proxy stripping headers. | Bypass the proxy or whitelist the Authorization header. |
| `account_suspended: payment_failed` | YOUR wholesale invoice failed. | Pay the wholesale invoice, suspension lifts within 5 min. |
| `tenant_not_found` | Service has no associated workspace yet. | Run **Module Commands → Create** again. |
| `suspended` | Customer's workspace is suspended. | Run `UnsuspendAccount` from WHMCS or fix the underlying dunning. |
| `terminated` | Workspace was hard-deleted. | A fresh `CreateAccount` will provision a new one with a NEW tenant id; previous data is gone. |
| `entitlements_invalid: max_compute_size unknown` | Config option 5 is not one of `nano/micro/small/medium/large/xlarge/2xl/4xl`. | Fix the product config and re-run ChangePackage. |
| `swarmz: SSO endpoint did not return a redirectTo URL` | Swarmz-side bug, very rare. | Open a Swarmz support ticket with the request id from the module log. |
| `Swarmz: Network error talking to swarmz API: ...` | Transport failure (DNS, TLS, timeout). | Whitelist `api.swarmz.net` outbound on 443; verify your server's cURL has a current CA bundle. |

### Module log

**Utilities → Logs → Module Log** is your friend. Filter by **Module
= swarmz**. The `Request` column shows the body the module sent (with
the bearer key redacted); `Response` shows the API's reply. Every
provisioning and SSO action writes one entry.

### Re-syncing a service

If WHMCS drifts out of sync with Swarmz (e.g. you ran SQL by hand):

1. On the admin service page, **Module Commands → Create**. The API
   is idempotent on the `whmcs:<serviceid>` external ref, so this
   re-fetches the existing tenant_id and writes it back to the custom
   field.
2. To force a fresh entitlements push: **Module Commands → Change
   Package**.
3. To pull current usage: **Module Commands → Usage Update**.

### Custom fields got deleted

If an admin accidentally deletes the `Swarmz Tenant ID` or `Swarmz
Dashboard URL` custom fields on a product:

1. The module will auto-recreate them on the next successful
   `CreateAccount` for any service of that product.
2. To repopulate values for existing services without re-running
   provision: **Module Commands → Create** on each service (no-op on
   the Swarmz side, but writes the custom field back).

### Tearing down a test enterprise (Swarmz-side)

If you bootstrapped a test enterprise account via the SQL functions
(see `test/smoke.php`), clean it up:

```sql
-- Find and delete all workspaces under the test enterprise
DELETE FROM workspaces
  WHERE enterprise_account_id = '<account_id>'::uuid;

-- Then the enterprise itself (cascades to keys, contract, etc.)
DELETE FROM enterprise_accounts
  WHERE id = '<account_id>'::uuid;
```

---

## Appendix A — End-to-end smoke test

A self-contained PHP smoke runner lives under `test/smoke.php` in this
repo. It loads the module outside WHMCS and exercises every hook against
the live Swarmz API:

```bash
SWARMZ_API_KEY=sk_live_…                                  \
SWARMZ_API_BASE=https://api.swarmz.net                    \
SWARMZ_TEST_SERVICE_ID=99999                              \
php test/smoke.php
```

A successful run prints `All steps passed.` and exits 0. Use this
before deploying the module to your production WHMCS — it catches
firewall, key, and CA-bundle issues before a customer hits them.

---

## Appendix B — Module file layout reference

```
modules/servers/swarmz/
├── swarmz.php                # Module entry point — implements all WHMCS hooks
├── hooks.php                 # Optional WHMCS action hooks (currently empty)
├── logo.png                  # 24×24 icon shown in the Servers dropdown
├── language/
│   └── english.php           # i18n strings
├── lib/
│   ├── Api.php               # Bearer-authed cURL client
│   ├── Helpers.php           # ConfigOptions → entitlements mapping, custom-field plumbing
│   └── Exceptions.php        # Typed exception hierarchy
└── templates/
    └── overview.tpl          # Smarty template for the client-area panel
```
