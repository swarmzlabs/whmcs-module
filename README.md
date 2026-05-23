# Swarmz WHMCS Module

Resell Swarmz AI workspaces directly from WHMCS. This is the official Swarmz
server module — a thin client over the Swarmz public enterprise API. All
business logic lives server-side; the module only translates WHMCS lifecycle
events into single-purpose API calls.

---

## What it does

When you sell a "Swarmz workspace" product in WHMCS, this module:

- Creates a workspace per WHMCS service (`CreateAccount`).
- Pushes plan changes through (`ChangePackage`).
- Suspends, unsuspends, and terminates the workspace in lock-step with the
  WHMCS service state.
- Provides a one-click "Open AI Editor" SSO button in both the client area and
  the admin service screen.
- Reports usage metrics (credits, cloud, projects) back to WHMCS so you can
  bill on top.

The module talks to a single base URL (default `https://api.swarmz.net`) over
HTTPS. Authentication is a `sk_live_…` bearer key stored in the WHMCS server's
**Password** field.

---

## Prerequisites

- WHMCS **8.x or newer**.
- PHP **8.1 or newer** with the `curl`, `json`, and `mbstring` extensions.
- An active Swarmz enterprise account (a `has_client_workspaces` enterprise
  account, see Swarmz docs).
- A Swarmz API key (`sk_live_…`) issued from your Swarmz admin area. The key
  is shown once on creation; rotate via the Swarmz admin if you lose it.

> Inside the WHMCS dev/staging environment, you can also use a sandbox key
> (`sk_test_…`) and point the module at a staging Swarmz API host.

---

## Installation

1. Copy the `modules/servers/swarmz` directory of this repo into your WHMCS
   install:

   ```
   <whmcs-root>/modules/servers/swarmz/
   ```

2. Set sensible permissions (files **644**, directories **755**):

   ```bash
   find <whmcs-root>/modules/servers/swarmz -type d -exec chmod 755 {} \;
   find <whmcs-root>/modules/servers/swarmz -type f -exec chmod 644 {} \;
   ```

3. Confirm the layout in WHMCS by opening
   **System Settings → Servers → Add New Server** and looking for the
   **Swarmz** option in the Module dropdown.

That's all — there's no activation step, no database migration, and no addon
to enable.

---

## Configure your server

1. **System Settings → Servers → Add New Server**.
2. Fill in:
   - **Name**: `Swarmz`
   - **Hostname**: `api.swarmz.net` (or your custom API host)
   - **Module**: select **Swarmz** from the dropdown
   - **Username**: `swarmz` (free text — only the password matters)
   - **Password**: paste your `sk_live_…` key here. WHMCS encrypts this field
     in `tblservers.password` automatically.
3. Click **Test Connection**. The module hits `/enterprise-sso` with a
   syntactically-valid but non-existent tenant id (`00000000-…`). A 4xx
   `tenant_not_found` reply proves the bearer key is valid without any
   side effects on the live data. A green tick means you're good to go.
4. Save the server.

> Tip: keep a separate **Sandbox** server entry pointing at a staging host
> with your `sk_test_…` key. Switch products between servers to test
> end-to-end before going live.

---

## Create a product

1. **System Settings → Products/Services → Products/Services → Create a New
   Product**.
2. Choose **Product Type: Hosting Account** (or `Other`), give it a name like
   *"Swarmz AI Starter"*.
3. On the **Module Settings** tab:
   - **Module Name**: Swarmz
   - **Server Group**: the group containing your Swarmz server
   - Set the per-product knobs:

     | Field | What it does | Empty / default |
     |-------|--------------|-----------------|
     | Credits per day | Daily AI credit budget | `5` (empty = unlimited) |
     | Monthly credit cap | Optional monthly ceiling | empty (none) |
     | Max projects | Project count limit | empty (unlimited) |
     | Max custom domains | Per workspace, each ≈ $0.10/mo | `0` |
     | Max compute size | Caps the editor's compute selector | `nano` |
     | Cloud budget cap (USD) | Optional per-workspace USD ceiling | empty (none) |
     | Initial credit top-up | Credits granted on first provision | `0` |

   - Tick **"Automatically setup the product as soon as an order is
     placed"** (or "after payment" — your call).
4. Save the product.

---

## Customer flow

```
Client orders product
        │
        ▼
WHMCS calls swarmz_CreateAccount
        │ POST /functions/v1/enterprise-create
        ▼
Swarmz creates a workspace, returns { tenant_id, dashboard_url }
        │
        ▼
WHMCS stores tenant_id + dashboard_url on the service
(optional initial top-up applied here)
        │
        ▼
Client opens the service in WHMCS → sees the "Open AI Editor" button
        │ POST /functions/v1/enterprise-sso
        ▼
Swarmz mints a one-shot token, returns { redirectTo: "..." }
        │
        ▼
Browser redirects to the Swarmz dashboard, signed-in.
```

Every endpoint accepts the WHMCS service ID as `external_ref` (string
`whmcs:<serviceid>`) so retries are idempotent — a duplicate
`CreateAccount` will return the existing tenant, not a new one.

---

## Suspend / unsuspend / terminate

WHMCS hits these endpoints when service state changes:

| WHMCS event | Endpoint | Effect on Swarmz |
|-------------|----------|------------------|
| SuspendAccount   | `/enterprise-suspend`   | Pauses pods + cloud, unpublishes sites, blocks SSO |
| UnsuspendAccount | `/enterprise-unsuspend` | Resumes pods + cloud, republishes the previously-live sites |
| TerminateAccount | `/enterprise-terminate` | Full teardown — pods deleted, sites unpublished, cloud destroyed |

All three are idempotent-by-state: retrying after the API has already settled
to the target state returns success rather than an error.

**Termination is permanent on the Swarmz side.** If a WHMCS admin reverses
a termination (e.g. by re-issuing a `Create` command on the same service),
the module calls `/enterprise-create` again with the same `external_ref`.
Because the previous workspace was hard-deleted, the API issues a *new*
tenant id and the module overwrites the `Swarmz Tenant ID` custom field
with the new value. The customer's previous projects, files, and history
are NOT restored — a fresh workspace is provisioned. If you need an
"unterminate" capability, suspend the WHMCS service instead of
terminating it; the data stays intact on the Swarmz side until you
explicitly terminate.

---

## Usage metrics

WHMCS calls `swarmz_UsageUpdate` on a schedule (and you can trigger it
manually from the admin service screen).

The module returns:

```php
[
    'success'        => true,
    'creditsUsed'    => 1245,    // from /enterprise-usage usage.credits_used
    'creditsLimit'   => 5000,    // from WHMCS product config (monthly_credit_cap)
    'usdCredits'     => 8.32,    // AI USD spend this month — usage.usd_credits
    'cloudUsd'       => 12.34,   // cloud USD this month — usage.cloud_usd
    'projectsCount'  => null,    // not returned by the live endpoint (null sentinel)
    'domainsCount'   => null,    // not returned by the live endpoint (null sentinel)
    'periodStart'    => '2026-05-01T00:00:00.000Z',  // ISO8601
    'periodEnd'      => '2026-06-01T00:00:00.000Z',
    'periodLabel'    => 'current_month',
    'raw'            => [/* full usage.* payload from /enterprise-usage */],
]
```

To wire these into Usage Billing or visible client-area fields, configure
WHMCS Usage Metrics (System Settings → Products → Configure Usage Metrics).
`creditsUsed` and `cloudUsd` are the most useful for usage billing.

---

## Security notes

- The bearer key lives in `tblservers.password` (encrypted at rest by WHMCS
  using the WHMCS encryption hash). **Never expose it to clients.**
- The module sends `Authorization: Bearer sk_live_…` only over HTTPS, with
  `CURLOPT_SSL_VERIFYPEER = true` and `VERIFYHOST = 2`.
- Every call is logged via WHMCS `logModuleCall`, with the bearer key and
  any `sk_live_`/`sk_test_` substring redacted from the saved payload.
- The module never echoes the bearer key in error strings shown to clients
  or admins.
- Rotate keys regularly through the Swarmz admin (your old key continues to
  work until you revoke it).

---

## Troubleshooting

### "Module Command Failed"

Open **Utilities → Logs → Module Log** and find the entry for the action
that failed. The `Request` column shows the body we sent (redacted), the
`Response` column shows the API's `{error, reason}` pair. Common causes:

- **`invalid_key`** — wrong or revoked API key. Re-paste the key into the
  server form and click **Test Connection**.
- **`tenant_not_found`** — the WHMCS service has no associated workspace yet.
  Run **Module Commands → Create** again from the admin service screen.
- **`suspended`** — the parent enterprise account is suspended (e.g. payment
  failed on the wholesale invoice). Resolve in the Swarmz admin first.

### Retry a failed provision

1. Admin service page → **Module Commands → Create**.
2. The `external_ref` is the same WHMCS service id, so the Swarmz API will
   return the existing tenant (or create one if none exists). No duplicates.

### Custom fields

On first successful provision, the module auto-creates two **admin-only**
product custom fields:

- `Swarmz Tenant ID`
- `Swarmz Dashboard URL`

These store the tenant info per service so subsequent calls can identify the
workspace even if the API base changes. Do not edit them by hand.

### Where the logs are

- WHMCS module log: **Utilities → Logs → Module Log** (filter `swarmz`).
- WHMCS activity log: **Utilities → Logs → Activity Log**.
- Swarmz-side: open your Swarmz admin and check the **Enterprise → API
  calls** stream for the failing request id.

---

## Smoke testing the module

This repo ships a self-contained smoke runner under `test/smoke.php`. It
loads the module outside WHMCS (stubbing the `WHMCS\Database\Capsule`
facade and `logModuleCall()`) and runs every server-module hook against
your live Swarmz API in sequence:

```
SWARMZ_API_KEY=sk_live_…                                  \
SWARMZ_API_BASE=https://ashyyneusxtubdhsfpod.supabase.co  \
SWARMZ_TEST_SERVICE_ID=99999                              \
SWARMZ_TEST_PRODUCT_ID=1                                  \
php test/smoke.php
```

The runner exercises the full provision-suspend-unsuspend-terminate
cycle, including idempotency (CreateAccount twice, SuspendAccount twice,
TerminateAccount twice) and the un-terminate-via-re-Create path (a
fresh tenant id is issued, the WHMCS service's custom field updated).

A successful run exits 0 with `All steps passed.`; any failure exits 1
and prints the bad step's response body. The smoke run creates and then
**terminates** the test workspace it provisions — but the parent
enterprise account, contract and key are persistent test fixtures that
you must clean up yourself (see `docs/HOSTING-COMPANY-ONBOARDING.md`
"Troubleshooting").

---

## Hosting-company onboarding guide

A separate step-by-step guide for hosting companies adopting the module
lives at [`docs/HOSTING-COMPANY-ONBOARDING.md`](docs/HOSTING-COMPANY-ONBOARDING.md).
It walks through the entire customer journey from "sign up for Swarmz
Enterprise" to "first paying customer" with reconciliation tips.

---

## Support

- Swarmz docs: https://swarmz.net/docs
- API reference: https://swarmz.net/docs/api/enterprise
- Issues with this module: https://github.com/swarmzlabs/whmcs-module/issues
  (private repo — request access from your Swarmz account manager).

---

## License

MIT — see `LICENSE`. You may copy, modify, and redistribute this module
freely, including inside hosting providers' control panels and customer-facing
installs.
