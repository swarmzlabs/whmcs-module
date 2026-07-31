{*
 * Client-area overview (rebuilt bones + structural themes, 1.16.0).
 *
 * WARNING: a Smarty comment ends at the FIRST asterisk-brace pair — never put
 * one inside this block. Spell template vars in words.
 *
 * LAYOUT RULES (the 1.13 grid looked broken — dead boxes, ragged rows):
 *   - A balance card renders ONLY when the pool exists on the plan (or the
 *     customer owns top-ups). No "Not included" placeholder boxes, ever.
 *   - Plan limits are a slim inline stat strip, not big cards.
 *   - "Buy more" lives in the credits section header and opens the packs
 *     modal (only mapped packs, direct order links).
 *
 * THEMES are structurally different compositions of the same DOM, selected
 * in the Reseller Console Appearance page: classic (neutral cards), swarmz
 * (flat hairline dashboard), cupertino (Apple: soft, extra-round, centered
 * hero), pulse (color-block hero + featured first card), carbon (dark dense
 * console rows), editorial (boxless typographic columns). Accent comes from
 * the Appearance page too (sets the swz-accent CSS variable).
 *
 * All strings come from the L array (language/<lang>.php, en/de/fr/it/es,
 * English fallback per key). Never hardcode user-facing English — add a key
 * to ALL five language files (see AGENTS.md). All arithmetic is done in PHP.
 *}

{assign var="ct" value=$creditTerm|default:'credits'}
{if $monthlyRemaining !== null}
    {assign var="monthlyRem" value=$monthlyRemaining}
{else}
    {assign var="monthlyRem" value=$monthlyCredits}
{/if}
{assign var="theme" value=$clientTheme|default:'classic'}

{* Pool visibility — decided once, up front. *}
{* A one-time free allowance that is fully used is a dead widget — hide it. *}
{assign var="showFree"    value=$freeKind !== 'none' && !($freeKind === 'one_time' && $freePct !== null && $freePct <= 0)}
{assign var="showMonthly" value=$monthlyCredits > 0}
{assign var="showTopup"   value=($topupRemaining !== null && $topupRemaining > 0) || ($topupUsed !== null && $topupUsed > 0)}
{assign var="showCloud"   value=$cloudMode !== 'none' && $cloudGrant > 0}
{assign var="showAi"      value=$aiMode !== 'none' && $aiGrant > 0}
{assign var="poolCount"   value=0}
{if $showFree}{assign var="poolCount" value=$poolCount+1}{/if}
{if $showMonthly}{assign var="poolCount" value=$poolCount+1}{/if}
{if $showTopup}{assign var="poolCount" value=$poolCount+1}{/if}
{if $showCloud}{assign var="poolCount" value=$poolCount+1}{/if}
{if $showAi}{assign var="poolCount" value=$poolCount+1}{/if}

<style>
/* ================= Shared skeleton ================= */
.swz-area { margin-top: 22px; --swz-accent: #4f46e5; }
.swz-area, .swz-area * { box-sizing: border-box; }
.swz-hero { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.swz-hero-title { margin: 0; font-size: 19px; font-weight: 700; letter-spacing: -.01em; }
.swz-hero-sub { margin: 5px 0 0; font-size: 13px; opacity: .6; display: flex; align-items: center; gap: 7px; }
.swz-live-dot { width: 8px; height: 8px; border-radius: 50%; background: #30a46c; box-shadow: 0 0 0 3px rgba(48,164,108,.18); display: inline-block; flex: 0 0 auto; }
.swz-launch { display: inline-flex !important; align-items: center; gap: 9px; transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease; }
.swz-launch:hover { transform: translateY(-1px); }
.swz-launch .swz-arrow { transition: transform .15s ease; display: inline-block; }
.swz-launch:hover .swz-arrow { transform: translateX(3px); }
.swz-sect { margin-top: 34px; }
.swz-sect-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 14px; }
.swz-sect-title { font-size: 12px; font-weight: 700; opacity: .55; text-transform: uppercase; letter-spacing: .07em; margin: 0; }
.swz-buy-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 650; cursor: pointer; white-space: nowrap; border: 0; background: none; color: var(--swz-accent); padding: 0; }
.swz-buy-btn:hover { opacity: .75; text-decoration: none; }
.swz-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; }
.swz-card { display: flex; flex-direction: column; gap: 9px; min-width: 0; }
.swz-card-label { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; opacity: .58; margin: 0; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.swz-cadence { font-size: 10.5px; font-weight: 600; letter-spacing: .02em; text-transform: none; border-radius: 999px; padding: 2px 8px; white-space: nowrap; background: color-mix(in srgb, var(--swz-accent) 10%, transparent); color: var(--swz-accent); }
.swz-num { font-size: 26px; font-weight: 750; line-height: 1.05; letter-spacing: -.01em; }
.swz-num small { font-size: 14px; font-weight: 600; opacity: .45; }
.swz-bar { height: 4px; border-radius: 999px; overflow: hidden; background: rgba(128,128,128,.15); }
.swz-bar-fill { height: 100%; border-radius: 999px; background: var(--swz-accent); }
.swz-low .swz-num, .swz-low .swz-card-label { color: #e5484d; }
.swz-low .swz-bar-fill { background: #e5484d; }
.swz-sub { font-size: 12px; opacity: .6; line-height: 1.45; margin: 0; }
.swz-sub .swz-muted { opacity: .8; }
.swz-empty { font-size: 13px; opacity: .65; margin: 0; }
/* Plan limits: one slim strip, not boxes. */
.swz-limits { display: flex; flex-wrap: wrap; gap: 8px 26px; align-items: baseline; font-size: 13px; }
.swz-limit { display: inline-flex; align-items: baseline; gap: 7px; white-space: nowrap; }
.swz-limit-label { opacity: .55; font-size: 12px; }
.swz-limit b { font-weight: 700; font-size: 14px; }
.swz-limit .swz-dim { opacity: .45; font-weight: 600; }
.swz-footer { margin-top: 28px; font-size: 12.5px; opacity: .6; }
@media (max-width: 640px) { .swz-launch { width: 100%; justify-content: center; } .swz-cards { grid-template-columns: 1fr 1fr; } }
@media (max-width: 420px) { .swz-cards { grid-template-columns: 1fr; } }

/* ================= Packs modal (shared) ================= */
.swz-modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(10,10,12,.55); backdrop-filter: blur(3px); z-index: 9998; }
.swz-modal { display: none; position: fixed; z-index: 9999; left: 50%; top: 50%; transform: translate(-50%,-50%); width: min(540px, calc(100vw - 32px)); max-height: min(640px, calc(100vh - 48px)); overflow: auto; background: #fff; color: #17181a; border-radius: 20px; box-shadow: 0 24px 80px rgba(0,0,0,.35); padding: 26px; }
.swz-modal.swz-open, .swz-modal-backdrop.swz-open { display: block; }
.swz-modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.swz-modal-title { margin: 0; font-size: 18px; font-weight: 750; letter-spacing: -.01em; }
.swz-modal-sub { margin: 4px 0 12px; font-size: 13px; opacity: .6; }
.swz-modal-close { border: 0; background: rgba(0,0,0,.06); color: inherit; border-radius: 10px; width: 32px; height: 32px; font-size: 16px; line-height: 1; cursor: pointer; flex: 0 0 auto; }
.swz-modal-close:hover { background: rgba(0,0,0,.11); }
.swz-pack { display: flex; align-items: center; gap: 14px; padding: 15px 2px; border-top: 1px solid rgba(0,0,0,.07); }
.swz-pack:first-of-type { border-top: 0; }
.swz-pack-main { flex: 1 1 auto; min-width: 0; }
.swz-pack-name { margin: 0; font-size: 14.5px; font-weight: 700; }
.swz-pack-desc { margin: 2px 0 0; font-size: 12px; opacity: .55; line-height: 1.4; }
.swz-pack-meta { margin-top: 6px; display: flex; align-items: center; gap: 8px; }
.swz-pack-chip { font-size: 10.5px; font-weight: 650; border-radius: 999px; padding: 3px 9px; white-space: nowrap; background: color-mix(in srgb, var(--swz-accent) 12%, transparent); color: var(--swz-accent); }
.swz-pack-price { font-size: 13px; font-weight: 650; opacity: .75; white-space: nowrap; }
.swz-pack-credits { font-size: 17px; font-weight: 800; white-space: nowrap; letter-spacing: -.01em; text-align: right; }
.swz-pack-credits small { font-size: 11px; font-weight: 600; opacity: .5; display: block; }
.swz-pack-order { display: inline-block; padding: 9px 17px; border: 0; cursor: pointer; border-radius: 11px; background: var(--swz-accent); color: #fff !important; font-size: 13px; font-weight: 700; text-decoration: none !important; white-space: nowrap; transition: opacity .15s ease, transform .15s ease; }
.swz-pack-order:hover { opacity: .88; transform: translateY(-1px); }
@media (max-width: 520px) { .swz-pack { flex-wrap: wrap; } .swz-pack-order { width: 100%; text-align: center; } }

/* ================= THEME: classic — quiet neutral cards ================= */
.swz-t-classic .swz-hero { border: 1px solid rgba(128,128,128,.15); border-radius: 16px; padding: 20px 22px; background: radial-gradient(1200px 240px at 0% 0%, rgba(128,128,128,.10), transparent 60%), rgba(128,128,128,.04); }
.swz-t-classic .swz-launch { font-size: 15px !important; font-weight: 600 !important; padding: 12px 22px !important; border-radius: 12px !important; box-shadow: 0 6px 18px rgba(0,0,0,.14); }
.swz-t-classic .swz-card { border: 1px solid rgba(128,128,128,.15); border-radius: 14px; padding: 18px 19px 17px; background: rgba(128,128,128,.03); }
.swz-t-classic .swz-cadence { background: none; border: 1px solid rgba(128,128,128,.28); color: inherit; opacity: .7; }
.swz-t-classic .swz-bar-fill { background: currentColor; opacity: .55; }
.swz-t-classic .swz-limits { border: 0; border-top: 1px solid rgba(128,128,128,.16); padding: 14px 2px 0; }
.swz-t-classic .swz-buy-btn { color: inherit; border: 1px solid currentColor; border-radius: 8px; padding: 5px 12px; }

/* ================= THEME: swarmz — flat hairline dashboard ================= */
.swz-t-swarmz { --swz-accent: #f97316; font-size: 13px; }
.swz-t-swarmz .swz-hero { border: 1px solid rgba(128,128,128,.22); border-left: 3px solid var(--swz-accent); border-radius: 6px; padding: 16px 18px; background: rgba(128,128,128,.025); }
.swz-t-swarmz .swz-hero-title { font-size: 16px; font-weight: 650; }
.swz-t-swarmz .swz-hero-sub { font-size: 12.5px; }
.swz-t-swarmz .swz-launch { font-size: 13px !important; font-weight: 600 !important; padding: 9px 16px !important; border-radius: 6px !important; background: var(--swz-accent) !important; border: 0 !important; color: #fff !important; box-shadow: none; }
.swz-t-swarmz .swz-sect { margin-top: 22px; }
.swz-t-swarmz .swz-sect-title { font-size: 11px; letter-spacing: .08em; }
.swz-t-swarmz .swz-cards { gap: 12px; grid-template-columns: repeat(auto-fit, minmax(148px, 1fr)); }
.swz-t-swarmz .swz-card { border: 1px solid rgba(128,128,128,.16); border-radius: 8px; padding: 13px 14px 12px; background: transparent; gap: 7px; }

.swz-t-swarmz .swz-num { font-size: 19px; font-weight: 700; }
.swz-t-swarmz .swz-bar { height: 3px; }
.swz-t-swarmz .swz-limits { padding: 12px 2px; border: 0; border-top: 1px solid rgba(128,128,128,.18); }
.swz-t-swarmz .swz-buy-btn { font-size: 12px; border: 1px solid var(--swz-accent); border-radius: 6px; padding: 5px 12px; }


/* ================= THEME: cupertino — Apple soft ================= */
.swz-t-cupertino { --swz-accent: #0a84ff; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", sans-serif; }
.swz-t-cupertino .swz-hero { flex-direction: column; align-items: center; text-align: center; gap: 14px; border: 0; border-radius: 26px; padding: 34px 24px 30px; background: rgba(128,128,128,.07); }
.swz-t-cupertino .swz-hero-title { font-size: 24px; font-weight: 700; letter-spacing: -.02em; }
.swz-t-cupertino .swz-hero-sub { justify-content: center; }
.swz-t-cupertino .swz-launch { font-size: 15px !important; font-weight: 600 !important; padding: 13px 30px !important; border-radius: 999px !important; background: var(--swz-accent) !important; border: 0 !important; color: #fff !important; box-shadow: none; width: auto !important; }
.swz-t-cupertino .swz-sect { margin-top: 24px; }
.swz-t-cupertino .swz-sect-title { text-transform: none; letter-spacing: -.01em; font-size: 16px; font-weight: 700; opacity: .9; }
.swz-t-cupertino .swz-cards { grid-template-columns: repeat(auto-fit, minmax(168px, 1fr)); gap: 13px; }
.swz-t-cupertino .swz-card { border: 0; border-radius: 18px; padding: 16px 17px 15px; background: rgba(128,128,128,.055); gap: 8px; }
.swz-t-cupertino .swz-card-label { text-transform: none; letter-spacing: 0; font-size: 13px; font-weight: 600; opacity: .55; }
.swz-t-cupertino .swz-num { font-size: 24px; font-weight: 700; letter-spacing: -.02em; }
.swz-t-cupertino .swz-bar { height: 6px; background: rgba(128,128,128,.16); }
.swz-t-cupertino .swz-cadence { font-weight: 600; }
.swz-t-cupertino .swz-limits { border-radius: 22px; background: rgba(128,128,128,.07); padding: 16px 21px; }
.swz-t-cupertino .swz-buy-btn { background: color-mix(in srgb, var(--swz-accent) 12%, transparent); border-radius: 999px; padding: 7px 16px; }

/* ================= THEME: pulse — color-block + featured card ================= */
.swz-t-pulse { --swz-accent: #f97316; }
.swz-t-pulse .swz-hero { border: 0; border-radius: 22px; padding: 26px 28px; background: linear-gradient(115deg, var(--swz-accent), color-mix(in srgb, var(--swz-accent) 72%, #000)); color: #fff; box-shadow: 0 10px 30px color-mix(in srgb, var(--swz-accent) 22%, transparent); }
.swz-t-pulse .swz-hero-title { font-size: 22px; font-weight: 800; }
.swz-t-pulse .swz-hero-sub { opacity: .85; }
.swz-t-pulse .swz-live-dot { background: #fff; box-shadow: 0 0 0 3px rgba(255,255,255,.25); }
.swz-t-pulse .swz-launch { font-size: 15px !important; font-weight: 750 !important; padding: 13px 26px !important; border-radius: 14px !important; background: #fff !important; color: var(--swz-accent) !important; border: 0 !important; box-shadow: 0 6px 20px rgba(0,0,0,.22); }
.swz-t-pulse .swz-cards { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
.swz-t-pulse .swz-card { border: 1px solid rgba(128,128,128,.15); border-radius: 18px; padding: 17px 18px 16px; background: rgba(128,128,128,.04); }
.swz-t-pulse .swz-card:first-child { grid-column: 1 / -1; flex-direction: row; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px 22px; background: color-mix(in srgb, var(--swz-accent) 7%, transparent); border-color: color-mix(in srgb, var(--swz-accent) 25%, transparent); }
.swz-t-pulse .swz-card:first-child .swz-num { font-size: 40px; font-weight: 800; }
.swz-t-pulse .swz-card:first-child .swz-bar { flex: 1 1 160px; height: 10px; }
.swz-t-pulse .swz-num { font-size: 30px; font-weight: 800; }
.swz-t-pulse .swz-bar { height: 7px; }
.swz-t-pulse .swz-sect-title { color: var(--swz-accent); opacity: .95; }
.swz-t-pulse .swz-limits { border-radius: 16px; background: color-mix(in srgb, var(--swz-accent) 6%, transparent); padding: 14px 18px; }
.swz-t-pulse .swz-buy-btn { background: var(--swz-accent); color: #fff; border-radius: 12px; padding: 8px 16px; font-weight: 750; }

/* ================= THEME: carbon — dark dense console rows ================= */
.swz-t-carbon { --swz-accent: #22d3ee; background: #0f1113; color: #e7e9ec; border-radius: 18px; padding: 20px; border: 1px solid rgba(255,255,255,.07); }
.swz-t-carbon .swz-hero { border-bottom: 1px solid rgba(255,255,255,.09); padding: 2px 2px 18px; }
.swz-t-carbon .swz-hero-title { font-size: 16px; font-weight: 650; }
.swz-t-carbon .swz-hero-sub { opacity: .5; font-size: 12.5px; }
.swz-t-carbon .swz-live-dot { background: var(--swz-accent); box-shadow: 0 0 10px color-mix(in srgb, var(--swz-accent) 70%, transparent); }
.swz-t-carbon .swz-launch { font-size: 13px !important; font-weight: 650 !important; padding: 10px 18px !important; border-radius: 8px !important; background: var(--swz-accent) !important; color: #0b0d0e !important; border: 0 !important; }
.swz-t-carbon .swz-sect { margin-top: 20px; }
.swz-t-carbon .swz-sect-title { opacity: .45; }
/* Narrow horizontal ROWS instead of card boxes — dense console density. */
.swz-t-carbon .swz-cards { grid-template-columns: 1fr; gap: 0; border: 1px solid rgba(255,255,255,.08); border-radius: 10px; overflow: hidden; }
.swz-t-carbon .swz-card { flex-direction: row; align-items: center; gap: 14px; padding: 12px 16px; background: #14171a; border-bottom: 1px solid rgba(255,255,255,.06); border-radius: 0; }
.swz-t-carbon .swz-card:last-child { border-bottom: 0; }
.swz-t-carbon .swz-card-label { flex: 0 0 235px; }
.swz-t-carbon .swz-bar { flex: 1 1 auto; order: 2; height: 4px; background: rgba(255,255,255,.09); }
.swz-t-carbon .swz-num { order: 3; flex: 0 0 118px; text-align: right; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-variant-numeric: tabular-nums; font-size: 16px; font-weight: 650; }
.swz-t-carbon .swz-sub { order: 4; flex: 0 0 215px; text-align: left; font-size: 11px; opacity: .45; }
.swz-t-carbon .swz-bar-fill { box-shadow: 0 0 10px color-mix(in srgb, var(--swz-accent) 60%, transparent); }
.swz-t-carbon .swz-low .swz-num, .swz-t-carbon .swz-low .swz-card-label { color: #ff6369; }
.swz-t-carbon .swz-limits { padding: 12px 16px; border: 1px solid rgba(255,255,255,.08); border-radius: 10px; background: #14171a; }
.swz-t-carbon .swz-buy-btn { border: 1px solid var(--swz-accent); border-radius: 7px; padding: 5px 12px; font-size: 12px; }
.swz-t-carbon .swz-footer a { color: var(--swz-accent); }
@media (max-width: 640px) { .swz-t-carbon .swz-card { flex-wrap: wrap; } .swz-t-carbon .swz-sub { text-align: left; } }

/* ================= THEME: editorial — boxless typographic ================= */
.swz-t-editorial { --swz-accent: #b45309; }
.swz-t-editorial .swz-hero { border: 0; border-top: 2px solid currentColor; border-bottom: 1px solid rgba(128,128,128,.25); padding: 26px 2px 24px; border-radius: 0; }
.swz-t-editorial .swz-hero-title { font-family: Georgia, 'Times New Roman', serif; font-size: 26px; font-weight: 500; }
.swz-t-editorial .swz-launch { font-size: 14px !important; font-weight: 600 !important; padding: 11px 22px !important; border-radius: 0 !important; background: transparent !important; color: inherit !important; border: 1.5px solid currentColor !important; box-shadow: none; }
.swz-t-editorial .swz-launch:hover { background: var(--swz-accent) !important; border-color: var(--swz-accent) !important; color: #fff !important; }
.swz-t-editorial .swz-sect-title { font-family: Georgia, 'Times New Roman', serif; font-style: italic; text-transform: none; letter-spacing: 0; font-size: 15px; font-weight: 500; opacity: .7; }
.swz-t-editorial .swz-cards { gap: 0; border-top: 1px solid rgba(128,128,128,.25); }
.swz-t-editorial .swz-card { border: 0; border-right: 1px solid rgba(128,128,128,.18); border-radius: 0; padding: 18px 20px 20px 14px; background: transparent; }
.swz-t-editorial .swz-card:last-child { border-right: 0; }
.swz-t-editorial .swz-card-label { letter-spacing: .12em; font-size: 10.5px; }
.swz-t-editorial .swz-num { font-family: Georgia, 'Times New Roman', serif; font-weight: 400; font-size: 34px; letter-spacing: -.02em; }
.swz-t-editorial .swz-num small { font-family: inherit; font-size: 16px; }
.swz-t-editorial .swz-cadence { background: none; padding: 2px 0; opacity: .55; color: inherit; font-weight: 500; }
.swz-t-editorial .swz-bar { height: 2px; border-radius: 0; }
.swz-t-editorial .swz-bar-fill { border-radius: 0; }
.swz-t-editorial .swz-limits { border-top: 1px solid rgba(128,128,128,.25); border-bottom: 1px solid rgba(128,128,128,.25); padding: 14px 2px; }
.swz-t-editorial .swz-buy-btn { border-bottom: 1.5px solid var(--swz-accent); border-radius: 0; padding: 0 0 1px; }
@media (max-width: 640px) { .swz-t-editorial .swz-card { border-right: 0; border-bottom: 1px solid rgba(128,128,128,.18); } }

/* ============ Dark-page adaptation (host WHMCS theme) ============ */
/* A tiny script tags the area swz-dark when the page background is dark, so
   the neutral grays and the modal flip without caring HOW the host's theme
   implements dark mode. Carbon is dark by design and overrides regardless. */
.swz-dark .swz-modal { background: #1b1e24; color: #e8eaee; box-shadow: 0 24px 80px rgba(0,0,0,.6); }
.swz-dark .swz-modal-close { background: rgba(255,255,255,.09); }
.swz-dark .swz-modal-close:hover { background: rgba(255,255,255,.16); }
.swz-dark .swz-pack { border-top-color: rgba(255,255,255,.08); }
.swz-dark .swz-bar { background: rgba(255,255,255,.12); }
.swz-dark .swz-limit-label, .swz-dark .swz-sub { opacity: .55; }

/* ============ Modals that match each layout ============ */
.swz-t-swarmz .swz-modal { border-radius: 10px; padding: 22px; }
.swz-t-swarmz .swz-modal-title { font-size: 16px; }
.swz-t-swarmz .swz-pack-order { border-radius: 7px; padding: 8px 15px; }
.swz-t-cupertino .swz-modal { border-radius: 26px; padding: 30px 28px; font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", sans-serif; }
.swz-t-cupertino .swz-modal-title { font-size: 20px; letter-spacing: -.02em; }
.swz-t-cupertino .swz-pack-order { border-radius: 999px; padding: 9px 20px; }
.swz-t-cupertino .swz-modal-close { border-radius: 50%; }
.swz-t-pulse .swz-modal { border-radius: 18px; border-top: 5px solid var(--swz-accent); }
.swz-t-pulse .swz-modal-title { font-size: 20px; font-weight: 800; }
.swz-t-pulse .swz-pack-order { border-radius: 12px; font-weight: 750; }
.swz-t-carbon .swz-modal { background: #14171a; color: #e7e9ec; border: 1px solid rgba(255,255,255,.09); border-radius: 12px; box-shadow: 0 24px 80px rgba(0,0,0,.65); }
.swz-t-carbon .swz-modal-close { background: rgba(255,255,255,.09); }
.swz-t-carbon .swz-pack { border-top-color: rgba(255,255,255,.08); }
.swz-t-carbon .swz-pack-credits { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.swz-t-carbon .swz-pack-order { color: #0b0d0e !important; border-radius: 8px; }
.swz-t-editorial .swz-modal { border-radius: 0; border-top: 2px solid currentColor; box-shadow: 0 24px 80px rgba(0,0,0,.35); }
.swz-t-editorial .swz-modal-title { font-family: Georgia, 'Times New Roman', serif; font-weight: 500; font-size: 22px; }
.swz-t-editorial .swz-pack-name { font-family: Georgia, 'Times New Roman', serif; font-weight: 600; }
.swz-t-editorial .swz-pack-credits { font-family: Georgia, 'Times New Roman', serif; font-weight: 500; }
.swz-t-editorial .swz-pack-order { border-radius: 0; background: transparent; color: var(--swz-accent) !important; border-bottom: 2px solid var(--swz-accent); padding: 4px 2px; }
.swz-t-editorial .swz-modal-close { border-radius: 0; background: transparent; border: 1px solid currentColor; }
</style>

<div class="swz-area swz-t-{$theme}"{if $accentHex} style="--swz-accent: {$accentHex};"{/if}>

    {* ---------- Hero ---------- *}
    <div class="swz-hero">
        <div>
            <h3 class="swz-hero-title">{$L.workspace_title}</h3>
            {if $tenantId}
                <p class="swz-hero-sub"><span class="swz-live-dot"></span> {$L.workspace_ready}</p>
            {else}
                <p class="swz-hero-sub">{$L.workspace_preparing}</p>
            {/if}
        </div>
        {if $tenantId}
            <form action="clientarea.php?action=productdetails" method="post" target="_blank" rel="noopener" style="display:inline;">
                <input type="hidden" name="id" value="{$serviceId}" />
                <input type="hidden" name="modop" value="custom" />
                <input type="hidden" name="a" value="launch" />
                <button type="submit" class="btn btn-primary swz-launch">{$editorButtonLabel|default:'Open AI Editor'|escape} <span class="swz-arrow">&rarr;</span></button>
            </form>
        {/if}
    </div>

    {if $packNotice}
        <div class="alert alert-success" style="margin-top:16px;">{$L.pack_added}</div>
    {/if}

    {if !$tenantId}
        <div class="alert alert-info" style="margin-top:16px;">{$L.provisioning_notice}</div>
    {else}

        {* ---------- Credits ---------- *}
        <div class="swz-sect">
            <div class="swz-sect-head">
                <p class="swz-sect-title">{$L.section_your|replace:'%s':$ct|escape}</p>
                {if $creditPacks|@count > 0}
                    <button type="button" class="swz-buy-btn" onclick="swzOpenPacks()">{$L.buy_button} <span class="swz-arrow">&rarr;</span></button>
                {/if}
            </div>

            {if $poolCount == 0}
                <p class="swz-empty">{$L.no_pools}</p>
            {else}
            <div class="swz-cards">

                {if $showFree}
                <div class="swz-card{if $freePct !== null && $freePct <= 12} swz-low{/if}">
                    <p class="swz-card-label">{$L.free_label|replace:'%s':$ct|escape}
                        {if $freeKind === 'daily'}<span class="swz-cadence">{$L.cadence_daily}</span>
                        {elseif $freeKind === 'monthly'}<span class="swz-cadence">{$L.cadence_monthly}</span>
                        {elseif $freeKind === 'one_time'}<span class="swz-cadence">{$L.cadence_one_time}</span>{/if}
                    </p>
                    {if $freeKind === 'unlimited'}
                        <div class="swz-num">&infin;</div>
                        <p class="swz-sub">{$L.unlimited}</p>
                    {else}
                        <div class="swz-num">{$freeRemainingFmt}<small> / {$freeTotalFmt}</small></div>
                        {if $freePct !== null}
                            <div class="swz-bar"><div class="swz-bar-fill" style="width:{$freePct}%;"></div></div>
                        {/if}
                        {if $freeKind === 'one_time'}
                            <p class="swz-sub">{$L.one_time_note}</p>
                        {elseif $freeKind === 'monthly'}
                            <p class="swz-sub">{$L.monthly_note}</p>
                        {else}
                            <p class="swz-sub">{$freeTotalFmt} {$L.per_day} &middot; {$L.resets_midnight}
                                {if $freeMonthlyCap !== null && $freeMonthlyCap > 0}
                                    {assign var="swzCapFmt" value=$freeMonthlyCap|string_format:"%d"}
                                    {assign var="swzCapStr" value="$swzCapFmt $ct"}
                                    <br><span class="swz-muted">{$L.up_to_month|replace:'%s':$swzCapStr|escape}</span>
                                {/if}
                            </p>
                        {/if}
                    {/if}
                </div>
                {/if}

                {if $showMonthly}
                <div class="swz-card{if $monthlyPct !== null && $monthlyPct <= 12} swz-low{/if}">
                    <p class="swz-card-label">{$L.monthly_label|replace:'%s':$ct|escape}
                        <span class="swz-cadence">{$L.cadence_cycle}</span>
                    </p>
                    <div class="swz-num">{$monthlyRem|string_format:"%d"}<small> / {$monthlyCredits|string_format:"%d"}</small></div>
                    {if $monthlyPct !== null}
                        <div class="swz-bar"><div class="swz-bar-fill" style="width:{$monthlyPct}%;"></div></div>
                    {/if}
                    <p class="swz-sub">
                        {$L.renews_cycle}
                        {if $rolloverCredits !== null && $rolloverCredits > 0}
                            {assign var="swzRollFmt" value=$rolloverCredits|string_format:"%d"}
                            <br><span class="swz-muted">{$L.rolled_over|replace:'%s':$swzRollFmt}</span>
                        {elseif $rolloverMonths > 0}<br><span class="swz-muted">{$L.carry_over|replace:'%s':$rolloverMonths}</span>{/if}
                    </p>
                </div>
                {/if}

                {if $showTopup}
                <div class="swz-card{if $topupPct !== null && $topupPct <= 12} swz-low{/if}">
                    <p class="swz-card-label">{$L.extra_label|replace:'%s':$ct|escape}
                        <span class="swz-cadence">{$L.cadence_topup}</span>
                    </p>
                    <div class="swz-num">{$topupRemaining|string_format:"%d"}{if $topupCredits !== null && $topupCredits > 0}<small> / {$topupCredits|string_format:"%d"}</small>{/if}</div>
                    {if $topupPct !== null}
                        <div class="swz-bar"><div class="swz-bar-fill" style="width:{$topupPct}%;"></div></div>
                    {/if}
                    <p class="swz-sub">{$L.topup_note}
                        {if $topupUsed !== null && $topupUsed > 0}
                            {assign var="swzTopupUsedFmt" value=$topupUsed|string_format:"%d"}
                            <br><span class="swz-muted">{$L.topup_used|replace:'%s':$swzTopupUsedFmt}</span>
                        {/if}
                    </p>
                </div>
                {/if}

                {if $showCloud}
                <div class="swz-card{if $cloudPct !== null && $cloudPct <= 12} swz-low{/if}">
                    <p class="swz-card-label">{$L.cloud_label|replace:'%s':$ct|escape}
                        {if $cloudMode === 'one_time'}<span class="swz-cadence">{$L.cadence_one_time}</span>
                        {else}<span class="swz-cadence">{$L.cadence_cycle}</span>{/if}
                    </p>
                    <div class="swz-num">{$cloudGrantRemaining|string_format:"%d"}<small> / {$cloudGrant|string_format:"%d"}</small></div>
                    {if $cloudPct !== null}
                        <div class="swz-bar"><div class="swz-bar-fill" style="width:{$cloudPct}%;"></div></div>
                    {/if}
                    <p class="swz-sub">{if $cloudMode === 'one_time'}{$L.one_time_note}{else}{$L.renews_cycle}{/if}</p>
                </div>
                {/if}

                {if $showAi}
                <div class="swz-card{if $aiPct !== null && $aiPct <= 12} swz-low{/if}">
                    <p class="swz-card-label">{$L.ai_label|replace:'%s':$ct|escape}
                        {if $aiMode === 'one_time'}<span class="swz-cadence">{$L.cadence_one_time}</span>
                        {else}<span class="swz-cadence">{$L.cadence_cycle}</span>{/if}
                    </p>
                    <div class="swz-num">{$aiGrantRemaining|string_format:"%d"}<small> / {$aiGrant|string_format:"%d"}</small></div>
                    {if $aiPct !== null}
                        <div class="swz-bar"><div class="swz-bar-fill" style="width:{$aiPct}%;"></div></div>
                    {/if}
                    <p class="swz-sub">{if $aiMode === 'one_time'}{$L.one_time_note}{else}{$L.renews_cycle}{/if}</p>
                </div>
                {/if}

            </div>
            {/if}
        </div>

        {* ---------- Plan limits: one slim strip ---------- *}
        <div class="swz-sect">
            <div class="swz-sect-head"><p class="swz-sect-title">{$L.plan_section}</p></div>
            <div class="swz-limits">
                <span class="swz-limit">
                    <span class="swz-limit-label">{$L.published_projects}</span>
                    {* "0 of 10" only when a REAL limit exists (limit > 0);
                       a bare count otherwise — "0 / 0" reads like nonsense. *}
                    {if $publishedCount !== null}
                        <b>{$publishedCount|string_format:"%d"}</b>{if $publishedLimit !== null && $publishedLimit > 0} <span class="swz-dim">{$L.of} {$publishedLimit|string_format:"%d"}</span>{/if}
                    {elseif $publishedLimit !== null && $publishedLimit > 0}
                        <b>{$publishedLimit|string_format:"%d"}</b> <span class="swz-dim">{$L.allowed_at_once}</span>
                    {elseif $publishedLimit === null}
                        <b>&infin;</b>
                    {else}
                        <b>0</b>
                    {/if}
                </span>
                <span class="swz-limit">
                    <span class="swz-limit-label">{$L.custom_domains}</span>
                    {if !$customDomainsEnabled}
                        <span class="swz-dim">{$L.not_available}</span>
                    {elseif $domainsCount !== null}
                        <b>{$domainsCount|string_format:"%d"}</b>{if $domainsLimit !== null && $domainsLimit > 0} <span class="swz-dim">{$L.of} {$domainsLimit|string_format:"%d"}</span>{/if}
                    {elseif $domainsLimit !== null && $domainsLimit > 0}
                        <b>{$domainsLimit|string_format:"%d"}</b> <span class="swz-dim">{$L.allowed}</span>
                    {else}
                        <b>&infin;</b>
                    {/if}
                </span>
            </div>
        </div>

        {if isset($usage.errorMsg) && $usage.errorMsg}
            <div class="alert alert-warning" style="margin-top:16px;">{$L.usage_error} {$usage.errorMsg|escape}</div>
        {/if}

        {if $supportUrl}
            <div class="swz-footer">{$L.need_help} <a href="{$supportUrl|escape}" target="_blank" rel="noopener">{$L.contact_support}</a>.</div>
        {/if}
    {/if}

    <script>
    (function () {
        try {
            var el = document.body, bg = '';
            while (el) {
                bg = window.getComputedStyle(el).backgroundColor || '';
                var m = bg.match(/rgba?\(([^)]+)\)/);
                if (m) {
                    var p = m[1].split(',');
                    if (p.length < 4 || parseFloat(p[3]) > 0.1) {
                        var lum = 0.2126 * p[0] + 0.7152 * p[1] + 0.0722 * p[2];
                        var area = document.querySelector('.swz-area');
                        if (area && lum < 100) { area.classList.add('swz-dark'); }
                        break;
                    }
                }
                el = el.parentElement;
            }
        } catch (e) { /* cosmetic only */ }
    })();
    </script>

    {* ---------- Packs modal ---------- *}
    {if $creditPacks|@count > 0}
        <div class="swz-modal-backdrop" id="swzPacksBackdrop" onclick="swzClosePacks()"></div>
        <div class="swz-modal" id="swzPacksModal" role="dialog" aria-modal="true" aria-labelledby="swzPacksTitle">
            <div class="swz-modal-head">
                <h4 class="swz-modal-title" id="swzPacksTitle">{$L.modal_title}</h4>
                <button type="button" class="swz-modal-close" onclick="swzClosePacks()" aria-label="{$L.close}">&times;</button>
            </div>
            <p class="swz-modal-sub">{$L.modal_sub} {$L.updates_fast}</p>
            {foreach from=$creditPacks item=pack}
                <div class="swz-pack">
                    <div class="swz-pack-main">
                        <p class="swz-pack-name">{$pack.name|escape}</p>
                        {if $pack.description}<p class="swz-pack-desc">{$pack.description|escape}</p>{/if}
                        <p class="swz-pack-meta">
                            <span class="swz-pack-chip">{if $pack.cycle === 'recurring'}{$L.chip_monthly}{else}{$L.chip_onetime}{/if}</span>
                            <span class="swz-pack-price">{if $pack.priceFmt}{$pack.priceFmt}{if $pack.cycle === 'recurring'}/mo{/if}{else}{$L.price_free}{/if}</span>
                        </p>
                    </div>
                    <div class="swz-pack-credits">{$pack.creditsFmt}<small>{$ct|escape}</small></div>
                    {* POST to the buypack module action: it puts the pack in
                       the cart SESSION server-side, then opens the cart view —
                       works on stock order forms AND themed ones (Lagom One
                       Step rewrites cart.php?a=add deep links to the generic
                       addons listing, so we never rely on those). *}
                    <form action="clientarea.php?action=productdetails" method="post" style="display:inline;margin:0;">
                        <input type="hidden" name="id" value="{$serviceId}" />
                        <input type="hidden" name="modop" value="custom" />
                        <input type="hidden" name="a" value="buypack" />
                        <input type="hidden" name="pack" value="{$pack.addonId}" />
                        <button type="submit" class="swz-pack-order">{$L.order_now}</button>
                    </form>
                </div>
            {foreachelse}
                <p class="swz-modal-sub">{$L.no_packs}</p>
            {/foreach}
        </div>
        <script>
        function swzOpenPacks() {
            document.getElementById('swzPacksModal').classList.add('swz-open');
            document.getElementById('swzPacksBackdrop').classList.add('swz-open');
        }
        function swzClosePacks() {
            document.getElementById('swzPacksModal').classList.remove('swz-open');
            document.getElementById('swzPacksBackdrop').classList.remove('swz-open');
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { swzClosePacks(); }
        });
        </script>
    {/if}
</div>
