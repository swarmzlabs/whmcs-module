# Changelog

All notable changes to this WHMCS module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.20.1] - 2026-08-10

### Fixed
- **Opening the Credit Packs page no longer crashes on installs where
  WHMCS's `generate_token()` returns null.** The v1.20.0 catalog view
  passed the CSRF token into a string-typed renderer, so a null from
  WHMCS became a fatal `TypeError`. All admin forms now source their
  token through one null-safe helper.

## [1.20.0] - 2026-07-31

### Changed
- **The Credit Packs page is now your Swarmz catalog.** One row per pack
  you defined in the plan builder — credits, price, billing, and exactly
  how (or whether) it is sold in WHMCS, with the linked addon's store
  status right there. Custom-amount mappings get their own small table,
  and the full by-addon table remains as the advanced view.

### Added
- **One-click "Create addon"**: for a pack not yet in WHMCS, the console
  creates the Product Addon as a **hidden draft** — correct billing
  cycle, assigned to your Swarmz products, price prefilled from the
  catalog, already linked. Review the price in WHMCS and untick
  "Hidden" to start selling; nothing is sellable before that. An addon
  already carrying the pack's name is adopted (linked), never
  duplicated. **Link existing** / **Unlink** actions per pack round it
  out.

## [1.19.1] - 2026-07-31

### Changed
- **Credit Packs page shows only your packs.** The console's mapping
  table now lists just the addons that are mapped as credit packs — not
  the whole addon catalog. A "Show all addons" link reveals the rest
  when you want to map a new one (and the first run, before anything is
  mapped, still shows everything). Hidden rows submit nothing, so the
  filter can never unmap an addon.

## [1.19.0] - 2026-07-31

### Changed
- **Credit packs now map to your Swarmz catalog.** The console's Credit
  Packs page offers a dropdown of the packs you define on Swarmz
  (Dashboard → Settings → Plans → Credit packs) instead of a hand-typed
  number — the catalog is the source of truth for what each pack is
  worth. Cached amounts re-sync automatically (daily, and whenever the
  page is opened), and every grant now reports which pack it sold as, so
  your Swarmz dashboard shows a per-pack **Sold** count. A **Custom
  amount** option keeps hand-typed mappings available, and existing
  mappings keep working unchanged. A pack removed from your catalog
  never silently unmaps — it keeps granting its last known amount and is
  labeled truthfully in the dropdown.

### Added
- `mod_swarmz_credit_packs` gains nullable `pack_code`/`pack_name`
  columns (additive only; existing rows and grants are untouched).

## [1.18.1] - 2026-07-31

### Changed
- **Editorial theme**: the "One-time" / "Monthly" chip in the packs popup
  is now plain muted text instead of a rounded badge, matching the theme's
  flat, airy treatment of the cycle labels on the credit cards.

## [1.18.0] - 2026-07-31

### Added
- **Checkout flow setting** (Console → Appearance): choose how the packs
  popup checks out — **Direct to invoice** (module places the order, the
  customer pays the invoice; works with every theme; recommended default),
  **Standard WHMCS cart** (classic cart deep link for stock order forms),
  or **Lagom Smart Order Form** (Lagom's addon store page — per its docs,
  Lagom routes ordering through order.php?m=OneStepOrder and offers no
  per-addon deep link, so its own store page is the correct target).
- **Visible confirmation for free packs**: a $0 pack is auto-accepted so
  it activates (and its credits grant) immediately, and the customer
  returns to the panel with a translated "Top-up added" notice instead of
  a bare WHMCS order screen.

## [1.17.6] - 2026-07-31

### Changed
- **Ordering a pack goes straight to the invoice.** Cart handoffs kept
  colliding with themed order forms (deep links rewritten; a session-
  seeded cart still landed on the order form's start page). The module
  now places the addon order itself via the AddOrder API — attached to
  the service, on the service's own payment method — and sends the
  customer directly to the invoice payment page, which renders the same
  on every theme. Fewer clicks: Order → pay. A $0 pack returns to the
  service page (its activation grant needs no invoice). Every order is
  logged as BuyPack.Ordered in the Module Log; failures surface a plain
  message instead of a broken checkout.

## [1.17.5] - 2026-07-31

### Fixed
- **The pack now actually arrives in the cart.** WHMCS closes the session
  early in client-area requests, so v1.17.4's cart write mutated an
  in-memory copy that was never saved — the checkout opened with an empty
  cart. The handoff now re-opens the session, writes, and closes it again,
  verified with a two-phase session harness.

## [1.17.4] - 2026-07-31

### Fixed
- **Order now actually opens the cart.** v1.17.3 seeded the cart session
  correctly but its redirect passed a relative URL to a helper that only
  accepts absolute ones (an SSO safety guard) — so the browser stayed on
  the product page with WHMCS's "Action Completed Successfully!" banner.
  The handoff now resolves the installation's SystemURL and redirects with
  **303 See Other**, the proper POST-to-GET code — which also removes the
  "confirm form resubmission" prompt on reload.

## [1.17.3] - 2026-07-31

### Fixed
- **Ordering a pack works on every order form, themed ones included.** The
  popup's Order button linked to `cart.php?a=add&aid=…`, which themed order
  forms rewrite — Lagom One Step routed it to the generic addons listing
  with the selection lost. The button now POSTs to a module action
  (`buypack`) that validates the pack against this service's mapped offers,
  puts it into the WHMCS cart session server-side (attached to the
  service), and only then opens the cart view — which every order form
  renders correctly once the item is already in the cart.

## [1.17.2] - 2026-07-31

### Fixed
- **Updates now install regardless of internal file ownership.** The
  overlay updated file-by-file, which needed write access inside every
  SUBDIRECTORY of the live module — one root-owned lib/ or language/
  folder and the update half-failed after a green preflight. The updater
  now assembles the complete new module tree beside the live one (every
  file created by PHP, so PHP owns it) and swaps it into place with two
  renames, which need write access only on modules/servers/ and
  modules/addons/ — exactly what the preflight checks. Hand-added files
  are carried over; the old tree is parked next to the backup if PHP
  can't delete it. Verified in a harness with root-owned, locked
  subdirectories — the precise state that failed on 14 files.
  Side effect: after one successful update, the whole module tree is
  PHP-owned and every future update is permission-proof.

## [1.17.1] - 2026-07-31

### Fixed
- **Language switcher now respected.** The client-area language dropdown
  stores its choice in the WHMCS session, not the client profile — the
  panel read only the profile, so switching to German changed nothing.
  Resolution is now session &rarr; profile &rarr; installation default.
- **Published projects reads like a sentence.** "Published projects 0 of
  10" when a real limit exists; a bare count when the limit is 0 or the
  plan is unlimited — no more "0 / 0". Same for custom domains. Label and
  the "of" connector translated in all five languages.

## [1.17.0] - 2026-07-31

### Added
- **Console: Top-up credits card** in "Balances right now" — the purchased
  credits your customers still hold, summed across workspaces (previously
  parsed but shown nowhere in the console).
- **Dark-page adaptation**: the customer panel detects a dark host theme
  (by page background, however the theme implements it) and flips the
  packs modal + neutral tones. Works with light and dark WHMCS themes on
  every layout; Carbon stays dark by design.
- **Modals match the layout**: the packs popup is themed per layout —
  hairline Swarmz, round Apple sheet for Cupertino, accent-topped Pulse,
  dark console Carbon, serif boxless Editorial.

### Changed
- Console build-credits caption: "Used of currently assigned, this cycle."
- **Swarmz and Cupertino layouts compacted** — smaller cards that fit four
  to a row on desktop.
- **A fully used one-time free allowance hides its card** instead of
  sitting there empty forever.
- Carbon rows use fixed columns so every credit bar starts and ends at the
  same position.

## [1.16.2] - 2026-07-31

### Fixed
- **Updater writes are now atomic and match the preflight.** Files are
  staged next to the target and renamed over it — needing only the
  directory write access the preflight verifies. The old in-place copy
  also needed write permission on each existing FILE, so installs whose
  files were owned by a different user than PHP passed preflight and then
  failed with "N file(s) could not be written". Verified in a harness
  running as `nobody` against root-owned files.
- **Honest error when the server module is unreadable.** If
  `modules/servers/swarmz/` exists but PHP can't read `lib/Api.php`
  (ownership/permissions after manual changes, or a partial upload), the
  console now explains that and how to fix it, instead of claiming the
  module is "not found".

## [1.16.1] - 2026-07-31

### Fixed
- **Updates page renders release notes properly.** Notes were shown as raw
  escaped markdown; they now render through a tiny safe subset (escape
  everything first, then bold, inline code, bullet lists, headings, and
  https links only) — verified against a script-injection probe.

## [1.16.0] - 2026-07-31

The client panel, rebuilt properly — and appearance moves into the console.

### Fixed
- **Template comment leaked into the page.** A Smarty comment ends at the
  first asterisk-brace pair; 1.13.0's header comment contained one, so half
  the comment rendered as visible text on every customer panel. Gone, with
  a render-harness check so it can never ship again.

### Changed
- **New layout bones.** Balance cards render ONLY for pools the plan (or the
  customer) actually has — no more "Not included" placeholder boxes and
  ragged half-empty grids. Plan limits are a slim inline strip instead of
  giant cards. "Buy more" sits in the credits header and opens the packs
  modal. Airier rhythm everywhere: lighter hairlines, more whitespace.
- **Six structurally different themes** (Appearance page): Classic (quiet
  cards), Swarmz (flat hairline dashboard), Cupertino (Apple-soft, centered
  hero, extra-round), Pulse (color-block hero + featured first card),
  Carbon (dark dense console rows), Editorial (boxless typographic).
  Aurora was folded into Cupertino.
- **Reseller Console redesign**: flat, airy, Swarmz-orange design system —
  segmented period tabs, hairline stat tiles instead of boxes, no duplicate
  page title — and every section rewritten in plain language ("Balances
  right now", "Your cost", one-line captions).

### Added
- **Console → Appearance page**: pick the customer-panel layout from visual
  preview cards, choose one of six accent schemes (or the per-theme
  default), or type an exact brand hex. Stored in the same settings as
  before, so existing choices carry over; the three fields are gone from
  the WHMCS module-settings form.

## [1.15.0] - 2026-07-30

The updater now protects hand-edited files.

### Added
- **Hand-modification guard.** Every release ships a per-file SHA-256
  manifest (`release-manifest.json`). Before updating, the module compares
  your live files against what your installed release shipped and lists any
  file you (or your team) changed by hand — typically customized templates.
  Overwriting them requires an explicit confirmation checkbox naming those
  exact files; without it the update refuses to run, enforced server-side,
  and the automatic backup is made either way. Installs updated from a
  pre-manifest version see a one-time blanket confirmation instead; precise
  per-file detection kicks in from the first manifest release onward.
- `scripts/build-release.py` — the release build now generates the manifest
  and the full-overlay ZIP in one step (see AGENTS.md).

## [1.14.0] - 2026-07-30

Update the module without leaving WHMCS.

### Added
- **In-admin updater.** The Reseller Console now shows a banner when a newer
  release is available (checks the official GitHub releases feed, cached for
  6 hours) and a new **Updates** page installs it in one click: download over
  verified TLS from the pinned repository, SHA-256 checksum verification
  against the digest GitHub publishes for the asset, a full path-allowlist
  scan of the archive, an automatic backup of both module directories, then
  an add/overwrite-only overlay. Nothing runs automatically — the install is
  an explicit, CSRF-protected admin action, and every environment
  precondition is shown on the page before the button is enabled. Settings,
  mappings, and customer data are untouched, exactly as with a manual ZIP
  upload.

### Changed
- **AGENTS.md** rewritten as a public engineering-standards document (the
  repository is read by the partners who run the module), now including the
  updater's non-negotiable security rules and the release discipline it
  depends on.

## [1.13.0] - 2026-07-30

Client-area revamp: themes, colors, translations, a packs modal, and the
missing top-up balance. Plus: Sync from Swarmz is removed.

### Added
- **Four new client-area themes** next to the untouched Classic —
  **Aurora** (soft glass + glow), **Pulse** (bold color-block hero),
  **Carbon** (self-contained dark panel), **Editorial** (typographic,
  boxless). Pick per host in the Reseller Console ("Client Theme").
- **Color system**: six preset schemes (mono, orange, green, red, blue,
  pink) plus a custom hex accent that overrides everything ("Color
  Scheme" / "Accent Color" settings). Buttons, bars, chips, and modal
  actions all follow the accent.
- **Top-up packs modal**: "Buy more" now opens an in-page modal listing
  ONLY the mapped credit packs for this product (name, credits, price,
  one-time/monthly chip) with direct add-to-cart links — customers no
  longer land on the WHMCS all-addons page showing unrelated addons.
- **Extra-credits card**: purchased top-ups are finally visible —
  remaining / lifetime purchased with a progress bar and 12-month note.
  Previously the balance was parsed from the API but rendered nowhere, so
  a top-up on a free plan looked like it never arrived.
- **Translations**: the entire customer-facing panel now renders in the
  client's WHMCS language — English, German, French, Italian, Spanish —
  via language/<lang>.php overlays with per-key English fallback. New
  AGENTS.md rule: every new user-facing string ships in all five files.

### Removed
- **Sync from Swarmz** (v1.12.x). In practice it adopted existing objects
  but refused to modify them, which read as "did nothing", and the extra
  concepts (adopt/link/preview) confused more than they helped. Manual
  product/addon setup plus the Credit Packs mapping page is the supported
  flow again; the sync registry table is left untouched per the AGENTS.md
  data-safety rules.

### Changed
- Credit Packs console page: shorter, plainer setup copy (three numbered
  steps).

## [1.12.1] - 2026-07-30

Stronger recognition of hand-built infrastructure, so a sync on an existing
install can never override or duplicate what's already there.

### Changed
- **Server recognition** now matches by the Swarmz module type OR by hostname
  (`api.swarmz.net` / the console's configured API base) — a server created by
  hand before the module was selected is adopted, not duplicated.
- **Product group adoption**: new products land in the group your existing
  Swarmz products already live in; a "Swarmz" product group is only created
  when at least one product actually needs creating (and none exists).
- **Addon adoption** gains a second deterministic signal: besides an exact
  name match, a single unlinked addon already *mapped* to exactly the pack's
  credit amount is adopted — the natural migration case for packs built by
  hand before the platform catalog existed.
- **Adoptions are recorded**: recognized servers/groups/products are linked in
  `mod_swarmz_sync_links` on Apply (shown as "Adopt" rows in the preview), so
  future syncs resolve them instantly and unambiguously. Adopted rows are
  never modified — the link is the only write.

## [1.12.0] - 2026-07-30

Sync from Swarmz: build the whole WHMCS catalog from the platform, so a
partner defines plans + credit packs once in their Swarmz dashboard and never
performs WHMCS product surgery by hand.

### Added
- **Console → Sync from Swarmz.** Preview-first, additive catalog builder:
  - Creates the **server** (module wired, SSL, key from the console) and a
    **server group** if none exist; creates the **"Swarmz" product group**.
  - Creates **one product per active platform plan** — plan code wired into
    Module Settings, priced in your default currency, auto-setup on order —
    and opens **upgrade/downgrade paths** between all synced products.
  - Creates **one store addon per platform credit pack** (priced, visible in
    the client-area store, assigned to all synced products) and **maps it**
    to its credit amount automatically. Pack changes on the platform update
    the mapping on re-sync; a retired pack hides the addon the sync created.
  - **Preview first, additive only, idempotent.** The GET page is strictly
    read-only; Apply only creates what the preview showed. Existing rows are
    never updated or deleted — a hand-built product already targeting a plan
    code (or a single addon with a pack's exact name) is *adopted*, not
    duplicated. Every created object is recorded in `mod_swarmz_sync_links`,
    so re-runs converge. Each step is individually guarded and logged as
    `Sync.*` in the Module Log.
- Requires platform support for `credit_packs` in the `platform-plans`
  response (partner dashboard → Settings → Plans → Credit packs).

## [1.11.1] - 2026-07-30

Credit-pack visibility + free-pack fixes, straight from first partner testing.

### Fixed
- **"Buy more credits" link gated on the wrong WHMCS flag.** The client-area
  buy link (and the Console's Store badge) keyed off `showorder` ("Show on
  Order Form"), which only controls the *initial checkout* flow. An addon
  with that box unticked was labeled "Hidden" and never got a buy link — even
  though existing customers could buy it from the client-area addon store.
  Both now key off the actual store flags: the **Hidden** checkbox (and
  Retired). The Console badge distinguishes *In store*, *In store + order
  form*, *Hidden*, and *Retired*, with tooltips explaining each.
- **Free-cycle packs never granted.** Grants were purely payment-triggered,
  but a Free addon produces no invoice, so it could never grant. Invoice-less
  packs (free cycle, or admin-added by hand) now grant **once on activation**
  via a new `AddonActivated` hook, idempotency key `whmcs-ha<id>-act`, with
  the daily sweep re-covering missed activations (last 30 days). Invoiced
  packs are untouched — payment remains their only trigger.

### Notes
- The Credit Packs table flags Free-cycle addons with a "grants on
  activation" badge so the different trigger is visible at a glance.

## [1.11.0] - 2026-07-28

Credit packs: sell extra Swarmz credits as ordinary WHMCS Product Addons. A
customer who runs out mid-build no longer dead-ends — even on your highest
plan they can buy a top-up pack and keep building.

### Added
- **Credit packs (Product Addons → Swarmz top-up credits).** Create a normal
  WHMCS Product Addon ("1,000 Extra Credits", one-time or recurring, no
  provisioning module on the addon), then map it to a credit amount in the
  Reseller Console's new **Credit Packs** page. Every PAID invoice line for a
  mapped addon grants the credits to the customer's workspace via
  `/platform-topup`:
  - **Payment is the trigger** — one-time addons grant once; recurring addons
    re-grant on every paid renewal invoice.
  - **Idempotent end-to-end** — the grant key is `whmcs-inv<invoice>-ha<addon>`
    and the platform dedupes on it, so re-fired hooks, the daily sweep, and
    provisioning catch-up can never double-grant.
  - **Self-healing** — if the pack was paid before the service was provisioned
    (the usual first-order sequence), `CreateAccount` grants it right after
    provisioning; a daily cron sweep re-checks the last 30 days of paid
    invoices for anything still missed.
  - **Wholesale metering** — the platform meters you for top-up credits at
    assignment (charge-on-assign); top-ups expire 12 months after purchase.
- **Console → Credit Packs page.** Lists every Product Addon with billing
  cycle, store visibility, and product assignments; set the credits per
  purchase inline (0/blank unmaps). Storage is a module-owned table
  (`mod_swarmz_credit_packs`), created on activate/upgrade.
- **Client-area "buy more" row.** The service overview shows a quiet
  "Buy \<credits\>" link to the addon store — only when the product actually
  has an orderable, mapped pack assigned, and worded with the host's own
  credit term.
- **Admin SSO — "Open workspace" on the Service Details tab.** Admins viewing
  a customer's product previously had only the unauthenticated dashboard URL
  (the old `AdminLink` SSO button targeted `sso.php?direct`, which requires a
  *client* session, and WHMCS renders `AdminLink` on the Servers config page
  anyway — dead on both counts). The Service Details tab now has an **Open
  workspace (signs you in)** button: it hits the Reseller Console's new
  `adminsso` action, which mints a fresh `platform-sso` redirect server-side
  and lands the admin inside the customer's workspace in a new tab. Failures
  (suspended / terminated / bad key / not provisioned) render as plain
  console notices; every mint is logged as `AdminSSO` in the Module Log. The
  dashboard URL row is kept but labeled "(unauthenticated link)".

### Notes
- Plan upgrades/downgrades need no new module code — WHMCS's native product
  Upgrade/Downgrade flow already lands in `ChangePackage`, which swaps the
  plan by `plan_code` with server-side proration. Configure the Upgrades tab
  on your products to open self-serve upgrades; see the WHMCS guide in the
  Swarmz docs.

## [1.10.0] - 2026-07-22

Reseller Console redesign: a minimal, monochrome UI and unambiguous usage
semantics. Presentation-only — no data fetching, API calls, or behavior
changed.

### Changed
- **Console restyled — monochrome, one accent.** The five differently-colored
  stat cards (purple/blue/teal/amber/green) are gone. The console now uses
  near-black/gray ink on white with hairline borders and generous whitespace;
  a single indigo accent is reserved for links and the active period tab.
  Status pills stay semantic but muted (tinted background + dark text instead
  of saturated fills), and notices/badges across the dashboard, Plans view and
  Prompt Box view follow the same palette. Still fully self-contained inline
  CSS — no external fonts or stylesheets.
- **Every number now says what it is.** The dashboard is reorganized into
  labelled sections:
  - *Credit usage · current cycle* — Active workspaces plus the Build / Cloud /
    AI lanes, each reading "used **of** granted" with a one-line caption
    (e.g. "Consumed vs the included grant this cycle"). The section states
    explicitly that these are **live current-cycle balances** which the period
    tabs do not scope (the tabs scope costs).
  - *Your wholesale cost · \<period\>* — the former "Billing summary", retitled
    and led by a new **Wholesale total** tile (AI spend + cloud spend — the
    same figure the old headline "Wholesale cost" card showed), with AI spend,
    Cloud spend (vs cap when the plans set one), Credits consumed, and — when
    the owner-authed billing summary is reachable — the Upcoming invoice and
    recent invoices. Captioned "What Swarmz bills you for the selected period —
    set your retail price in WHMCS product pricing."
  - *Customers* — the table is unchanged structurally, but the used/included
    convention and the meaning of a dash ("no live balance reported for that
    lane") are explained once in a caption above the table instead of per-cell
    noise; the footer note is trimmed to tenant-id + wholesale-cost semantics.
- **The billing-summary explainer is a compact footnote** (why purchased
  credits / rollover / upcoming invoice live on the Swarmz billing page and
  can't be read with the reseller API key) instead of a wall-of-text notice.

### Versions
- Reseller Console addon `version` bumped `1.9.0` → **`1.10.0`**.
  `Api::VERSION` is unchanged — this release contains no API-client changes.

## [1.9.0] - 2026-07-18

The storefront release: an embeddable **Prompt Box** that carries a visitor's
first prompt from ANY page into their provisioned workspace, a redesigned
client area, and SSO/transport hardening.

### Added
- **Prompt Box — capture the first prompt on the host's own site.** One
  `<script>` tag (plain HTML, WordPress, any builder) renders a themeable,
  dependency-free prompt widget (Shadow DOM — the host page's CSS can't break
  it, and vice versa). A visitor types the app they want (optionally picks a
  plan inline via `data-plans`), lands in the WHMCS cart with an opaque
  `swzp` intent token, and when the order provisions, `CreateAccount`
  attaches the prompt to `platform-create` as `initial_prompt` — the
  customer's **first login opens the editor with that app already
  building** (requires platform API 2026-07-18+; older APIs simply ignore
  the field).
  - New public endpoint `modules/addons/swarmz/promptbox.php`
    (`?a=js` widget / `?a=intent` capture). Public by design: per-IP rate
    limiting, 10k-char cap, swarmz-product allow-listing, 30-day retention.
  - New table `mod_swarmz_prompt_intents` (created on addon
    activate/upgrade, lazily as fallback). Checkout binding via addon hooks
    (`ClientAreaPage` session capture → `AfterShoppingCartCheckout` +
    `PreModuleCreate` double-binder, so instant-activation free products
    can't outrun the bind).
  - New **Prompt Box** view in the Reseller Console: snippet builder
    (product / labels / theme / accent) with a live inline preview and a
    "recently captured prompts" table showing each prompt's journey
    (Captured → Ordered → Provisioned).
- **Automatic transport retries.** All API calls retry cURL transport
  failures and gateway statuses (500/502/503/504/522/524) up to 2× with
  backoff — safe because every module-called endpoint is idempotent. A cold
  start or deploy blip during an SSO click is now a non-event instead of
  "Network error talking to swarmz API".

### Changed
- **Client area redesigned.** Status hero with a live-dot + animated launch
  button (inherits the WHMCS theme's own brand color), per-pool credit cards
  with remaining-progress bars (low-balance <13% turns the card red), grant
  cadence chips (Daily / Monthly / One-time / Per cycle), tightened
  typography and full mobile responsiveness. Still 100% unbranded and
  theme-neutral (derives every tone from the surrounding theme).
- **SSO refusals are now customer-readable.** `suspended` / `terminated` /
  `tenant_not_found` / auth errors map to plain-language, unbranded messages
  with a next step ("check your billing status or contact support") instead
  of raw API vocabulary. The raw error still lands in the Module Log.

### Versions
- `lib/Api.php`: `Api::VERSION` bumped `1.8.0` → `1.9.0`; Reseller Console
  addon `version` bumped to `1.9.0` (adds `swarmz_upgrade` to create the
  prompt-intents table on in-place updates).

## [1.8.0] - 2026-07-12

Grant-cadence display pass: the client area now shows every credit pool the
way the plan actually grants it, instead of assuming free credits are daily
and Cloud/AI renew each cycle.

### Fixed
- **One-time / monthly free credits no longer render as "0 / 0".** The
  client-area free-credits card read only `caps.credits_per_day`, which is `0`
  for plans granting free credits `one_time` or `monthly` — a customer holding
  a 15-credit one-time grant (7.5 already spent) saw
  "0 / 0 · 0/day · resets daily (00:00 UTC) · Up to 15 credits/month". The
  card now follows the plan's `free_credit_mode` and the live free-lane
  counters the platform's spend function actually enforces, rendering
  "7.5 / 15 · One-time allowance · does not renew" (and the monthly-mode
  equivalent "11 / 15 · Replenishes monthly"). Fractional free-lane figures
  keep one decimal instead of truncating.
- **Plans with no free credits show "— · Not included on this plan"** (like
  the other pools) instead of a fabricated "0 / 0 · resets daily".

### Changed
- **Cloud/AI credit cards say how the grant renews.** The sub-line follows the
  plan's `cloud_credit_mode` / `ai_credit_mode`: "Renews each billing cycle"
  for monthly grants, "One-time allowance · does not renew" for one-time
  grants, "Not included on this plan" for none. (Requires the 2026-07 platform
  API; older APIs keep the previous per-cycle wording.)
- **Reseller Console plans table is cadence-aware.** The "Free/day" column is
  now "Free" and renders the configured grant with its cadence ("5/day",
  "15/mo", "15 once"); new "Cloud" and "AI" columns show those lanes'
  grants ("20/cycle", "20 once") from the platform-plans response.
- The free-card display decision is resolved in PHP
  (`_swarmz_freeCardView()`) and handed to the template pre-formatted; the
  template no longer does credit arithmetic. The legacy `freeDaily` /
  `freeDailyUsed` variables are still passed for field-modified templates.
- `lib/Api.php`: `Api::VERSION` bumped `1.7.0` → `1.8.0`; Reseller Console
  addon `version` bumped to `1.8.0`.

### Compatibility
- Fully backward-compatible with older platform APIs: when the response lacks
  the new `free_*` counters / `*_credit_mode` caps, the module keeps the
  previous daily-allowance rendering. Pairs best with platform API 2026-07-12
  or later (`platform-usage` balances carry the free lane + grant modes;
  `platform-plans` carries `monthly_cloud_credits` / `monthly_ai_credits` +
  `cloud_credit_mode` / `ai_credit_mode`).

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
