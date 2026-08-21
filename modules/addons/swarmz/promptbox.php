<?php
/**
 * Swarmz Prompt Box — public endpoint.
 *
 *   GET  ?a=js      → the embeddable widget script (host pastes ONE <script>
 *                     tag on any page: plain HTML, WordPress, any builder).
 *   POST ?a=intent  → stores a visitor's prompt as an intent, returns the
 *                     cart redirect carrying an opaque token.
 *   POST ?a=express → "Frictionless onboarding" (opt-in, off by default):
 *                     creates the WHMCS client + a $0 order from just an
 *                     email + password and returns a redirect straight into
 *                     the builder. See lib/ExpressSignup.php.
 *
 * This file is the ONLY public surface of the prompt-box feature. It boots
 * WHMCS (for DB + SystemURL) but requires no authentication: the stored
 * prompt is inert visitor input — it only ever reaches the Swarmz platform
 * attached to a real provisioned service, through the host's own API key.
 * Abuse is bounded by per-IP rate limiting, a size cap, product allow-listing
 * (swarmz-module products only) and 30-day retention. a=express carries its
 * own, stricter per-IP ceiling (see PromptBox::EXPRESS_RATE_LIMIT_PER_HOUR)
 * since it also creates a WHMCS client and places an order per attempt.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

use WHMCS\Module\Addon\Swarmz\ExpressSignup;
use WHMCS\Module\Addon\Swarmz\PromptBox;

require __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/PromptBox.php';
require_once __DIR__ . '/lib/ExpressSignup.php';

// Optional cross-module dependency (same as Console.php): the express-signup
// settings live on the provisioning (server) module's Helpers class, since
// that's where every other console-managed setting (theme, checkout flow,
// …) already lives. Absent server module → these read as "off"/"" below.
$swarmzServerLib = __DIR__ . '/../../servers/swarmz/lib';
if (is_file($swarmzServerLib . '/Api.php')) {
    require_once $swarmzServerLib . '/Exceptions.php';
    require_once $swarmzServerLib . '/Api.php';
    require_once $swarmzServerLib . '/Helpers.php';
}

// CORS: the widget lives on the HOST'S marketing site (different origin from
// WHMCS). The endpoint neither reads cookies nor returns anything private, so
// a wildcard is appropriate here.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = isset($_GET['a']) ? (string) $_GET['a'] : 'js';

if ($action === 'intent') {
    header('Content-Type: application/json');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $body = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($body)) {
        $body = [];
    }
    $prompt = isset($body['prompt']) && is_string($body['prompt']) ? $body['prompt'] : '';
    $pid = isset($body['pid']) && is_numeric($body['pid']) ? (int) $body['pid'] : 0;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    [$ok, $tokenOrError] = PromptBox::createIntent($prompt, $pid, $ip);
    if (!$ok) {
        http_response_code($tokenOrError === 'rate_limited' ? 429 : 422);
        echo json_encode(['ok' => false, 'error' => $tokenOrError]);
        exit;
    }
    echo json_encode([
        'ok'       => true,
        'token'    => $tokenOrError,
        'redirect' => PromptBox::cartUrl($pid, $tokenOrError),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'express') {
    header('Content-Type: application/json');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
        exit;
    }
    // Give the AddClient → AddOrder → AcceptOrder → platform-sso chain room to
    // finish in one request: a mid-chain max_execution_time cutoff is the one
    // way a signup can still strand a Pending order (no shutdown hook runs).
    // Best-effort — hosts that disable set_time_limit simply keep their default.
    if (function_exists('set_time_limit')) { @set_time_limit(120); }

    $raw = file_get_contents('php://input');
    $body = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($body)) {
        $body = [];
    }
    // IP comes from the connection, never from the body itself.
    $body['ip'] = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

    $result = ExpressSignup::run($body);
    if (empty($result['ok'])) {
        $error = isset($result['error']) && is_string($result['error']) ? $result['error'] : 'signup_failed';
        http_response_code(ExpressSignup::HTTP_STATUS[$error] ?? 422);
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    echo json_encode(['ok' => true, 'redirect' => $result['redirect']], JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// a=js — the widget. Per-page options are data-* attributes on the embedding
// <script> tag, but the response ALSO bakes in two console settings resolved
// at request time (Frictionless onboarding on/off + Terms URL). Those must
// take effect the moment a host flips the toggle, so this response is NOT
// cacheable — a stale copy behind Cloudflare/LiteSpeed/the browser is exactly
// what makes an enabled toggle appear to do nothing. The script is small and
// dependency-free, so serving it fresh per load is cheap; correctness of the
// signup mode beats caching a few KB.
// ---------------------------------------------------------------------------
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$endpoint = rtrim(PromptBox::systemUrl(), '/') . '/modules/addons/swarmz/promptbox.php?a=intent';
$endpointJson = json_encode($endpoint, JSON_UNESCAPED_SLASHES);

// Frictionless onboarding (v1.21.0): EXPRESS/TOS_URL are baked into the
// served JS at request time from the two console settings, so flipping the
// toggle takes effect the next time this response is generated — up to the
// Cache-Control max-age below on already-cached pages. When EXPRESS is
// false the widget behaves exactly as it always has (see EXPRESS branches
// in the script body).
$expressEnabled = class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Helpers')
    && \WHMCS\Module\Server\Swarmz\Helpers::expressSignupEnabled();
$expressJson = $expressEnabled ? 'true' : 'false';
$tosUrl = class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Helpers')
    ? \WHMCS\Module\Server\Swarmz\Helpers::expressTosUrl()
    : '';
$tosUrlJson = json_encode($tosUrl, JSON_UNESCAPED_SLASHES);
$expressEndpoint = rtrim(PromptBox::systemUrl(), '/') . '/modules/addons/swarmz/promptbox.php?a=express';
$expressEndpointJson = json_encode($expressEndpoint, JSON_UNESCAPED_SLASHES);
// WHMCS 8's client login route; works whether or not pretty URLs are on.
$loginUrl = rtrim(PromptBox::systemUrl(), '/') . '/index.php?rp=/login';
$loginUrlJson = json_encode($loginUrl, JSON_UNESCAPED_SLASHES);
// Minimum password length (console setting), mirrored into the widget for
// instant client-side feedback. The server (ExpressSignup) stays authoritative.
$minPassword = class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Helpers')
    ? \WHMCS\Module\Server\Swarmz\Helpers::expressMinPassword()
    : 8;
$minPasswordJson = (int) $minPassword;

echo <<<JS
/*! Prompt Box widget (self-contained, dependency-free).
 *
 * Embed:
 *   <script src="https://YOUR-WHMCS/modules/addons/swarmz/promptbox.php?a=js"
 *           data-pid="12"
 *           data-button="Start building"
 *           data-placeholder="Describe the app you want to build…"
 *           data-theme="auto"           (auto | light | dark)
 *           data-accent="#4f46e5"
 *           data-plans='[{"pid":12,"label":"Starter","price":"$9/mo"}]'
 *           async></script>
 *
 * With data-plans the visitor picks a plan inline; with only data-pid the
 * widget submits straight into that product. Renders where the tag sits, or
 * into the element matching data-target (a CSS selector) when given.
 *
 * FRICTIONLESS ONBOARDING (host turns it on in Reseller Console → Prompt Box):
 * submitting the prompt opens a sign-up POPUP (email + password) and the
 * visitor lands straight in the builder. When it is OFF, submitting goes to the
 * classic cart flow, unchanged.
 *
 * CUSTOMISE EVERYTHING — three ways, most-to-least turnkey:
 *  1. data-* copy: data-express-title, data-express-subtitle, data-email-label,
 *     data-email-placeholder, data-password-label, data-password-placeholder,
 *     data-express-button, data-login-text, data-terms-text.
 *  2. CSS custom properties (inherit through the shadow root — set them on the
 *     host element or an ancestor and they apply): --spb-accent, --spb-bg,
 *     --spb-fg, --spb-muted, --spb-border, --spb-input-bg, --spb-radius,
 *     --spb-font, --spb-modal-bg, --spb-overlay, --spb-btn-fg. Every element
 *     also carries a stable class (spb, spb-field, spb-go, spb-modal,
 *     spb-modal-card, spb-input, spb-label, spb-submit, spb-login-link,
 *     spb-close, spb-overlay …) you can target from your own stylesheet.
 *  3. Headless (data-express-mode="headless"): the widget renders the prompt
 *     box but NO built-in popup — build your own sign-up UI and drive it via
 *     window.SwarmzPromptBox:
 *        SwarmzPromptBox.submit({prompt,email,password,tos}) -> Promise<{ok,redirect?,error?}>
 *        SwarmzPromptBox.on("prompt", fn)  // fn({prompt,pid}) when a prompt is submitted
 *        SwarmzPromptBox.on("result", fn)  // fn({ok,redirect?,error?}) after submit()
 *     The script's host element also dispatches CustomEvents "swarmz:prompt"
 *     and "swarmz:express-result" with the same detail payloads.
 */
(function () {
  "use strict";
  var script = document.currentScript;
  if (!script) return;
  var ENDPOINT = {$endpointJson};
  var EXPRESS = {$expressJson};
  var EXPRESS_ENDPOINT = {$expressEndpointJson};
  var TOS_URL = {$tosUrlJson};
  var LOGIN_URL = {$loginUrlJson};
  var MIN_PASSWORD = {$minPasswordJson};

  function attr(name, dflt) { var v = script.getAttribute(name); return (v === null || v === "") ? dflt : v; }

  var cfg = {
    pid: parseInt(attr("data-pid", "0"), 10) || 0,
    button: attr("data-button", "Start building"),
    placeholder: attr("data-placeholder", "Describe the app you want to build\\u2026"),
    theme: attr("data-theme", "auto").toLowerCase(),
    accent: attr("data-accent", "#4f46e5"),
    target: attr("data-target", ""),
    mode: attr("data-express-mode", "modal").toLowerCase(),
    expressTitle: attr("data-express-title", "Create your account"),
    expressSubtitle: attr("data-express-subtitle", "One step and your app starts building."),
    emailLabel: attr("data-email-label", "Email address"),
    emailPlaceholder: attr("data-email-placeholder", "you@example.com"),
    passwordLabel: attr("data-password-label", "Password"),
    passwordPlaceholder: attr("data-password-placeholder", "At least " + MIN_PASSWORD + " characters"),
    expressButton: attr("data-express-button", "Create account & start building"),
    loginText: attr("data-login-text", "Already have an account? Log in"),
    termsText: attr("data-terms-text", "I agree to the terms"),
    maxLen: 10000,
    plans: []
  };
  try {
    var plansRaw = script.getAttribute("data-plans");
    if (plansRaw) {
      var parsed = JSON.parse(plansRaw);
      if (Array.isArray(parsed)) {
        cfg.plans = parsed.filter(function (p) { return p && typeof p === "object" && parseInt(p.pid, 10) > 0; });
      }
    }
  } catch (e) { /* bad data-plans JSON → single-product mode */ }
  if (!cfg.pid && cfg.plans.length) cfg.pid = parseInt(cfg.plans[0].pid, 10);
  if (!cfg.pid) return; // nothing to order into — refuse quietly
  var headless = EXPRESS && cfg.mode === "headless";

  function mountHost() {
    if (cfg.target) { var t = document.querySelector(cfg.target); if (t) return t; }
    var host = document.createElement("div");
    script.parentNode.insertBefore(host, script.nextSibling);
    return host;
  }

  function darkPreferred() {
    if (cfg.theme === "dark") return true;
    if (cfg.theme === "light") return false;
    return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
  }

  // ---- window.SwarmzPromptBox: the programmatic API (always available, and
  // the ONLY interface in headless mode). Events fan out to on() subscribers
  // AND as CustomEvents on the script's host element. ----
  var listeners = { prompt: [], result: [] };
  function emit(name, detail) {
    (listeners[name] || []).forEach(function (fn) { try { fn(detail); } catch (e) {} });
    try { script.dispatchEvent(new CustomEvent("swarmz:" + (name === "result" ? "express-result" : name), { bubbles: true, detail: detail })); } catch (e) {}
  }
  function apiSubmit(data) {
    data = data || {};
    return new Promise(function (resolve) {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", EXPRESS_ENDPOINT, true);
      xhr.setRequestHeader("Content-Type", "application/json");
      function done(res) { emit("result", res); resolve(res); }
      xhr.onload = function () {
        var body = null;
        try { body = JSON.parse(xhr.responseText); } catch (e) {}
        if (xhr.status >= 200 && xhr.status < 300 && body && body.ok && body.redirect) {
          done({ ok: true, redirect: body.redirect });
        } else {
          done({ ok: false, error: (body && body.error) || "request_failed" });
        }
      };
      xhr.onerror = function () { done({ ok: false, error: "network_error" }); };
      xhr.send(JSON.stringify({
        prompt: data.prompt, pid: parseInt(data.pid, 10) || cfg.pid,
        email: data.email, password: data.password, tos: !!data.tos
      }));
    });
  }
  window.SwarmzPromptBox = window.SwarmzPromptBox || {
    submit: apiSubmit,
    on: function (evt, cb) { if (listeners[evt] && typeof cb === "function") listeners[evt].push(cb); return this; },
    open: function () {} // replaced with the real opener below when a modal exists
  };

  function boot() {
    var host = mountHost();
    var root = host.attachShadow ? host.attachShadow({ mode: "open" }) : host;
    var dark = darkPreferred();

    // Concrete defaults resolved from theme + accent. Exposed as CSS custom
    // properties on :host so a host page can override any of them (custom
    // properties inherit across the shadow boundary).
    var d = {
      bg: dark ? "#101014" : "#ffffff",
      fg: dark ? "#f4f4f6" : "#16161a",
      muted: dark ? "rgba(244,244,246,.55)" : "rgba(22,22,26,.55)",
      border: dark ? "rgba(255,255,255,.12)" : "rgba(22,22,26,.12)",
      inputbg: dark ? "rgba(255,255,255,.05)" : "rgba(22,22,26,.03)",
      chip: dark ? "rgba(255,255,255,.06)" : "rgba(22,22,26,.05)",
      modalbg: dark ? "#17171c" : "#ffffff",
      overlay: "rgba(8,8,12,.62)",
      shadow: dark ? ".45" : ".08"
    };

    var style = document.createElement("style");
    style.textContent =
      ":host{all:initial;" +
        "--spb-accent:" + cfg.accent + ";--spb-bg:" + d.bg + ";--spb-fg:" + d.fg + ";--spb-muted:" + d.muted + ";" +
        "--spb-border:" + d.border + ";--spb-input-bg:" + d.inputbg + ";--spb-chip:" + d.chip + ";" +
        "--spb-modal-bg:" + d.modalbg + ";--spb-overlay:" + d.overlay + ";--spb-btn-fg:#ffffff;" +
        "--spb-radius:14px;--spb-font:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif}" +
      ".spb{box-sizing:border-box;font-family:var(--spb-font);background:var(--spb-bg);color:var(--spb-fg);" +
        "border:1px solid var(--spb-border);border-radius:16px;padding:18px;max-width:640px;box-shadow:0 8px 30px rgba(0,0,0," + d.shadow + ")}" +
      ".spb *{box-sizing:border-box;font-family:inherit}" +
      ".spb-field{display:block;width:100%;min-height:96px;resize:vertical;border:1px solid var(--spb-border);" +
        "border-radius:12px;background:var(--spb-input-bg);color:var(--spb-fg);padding:12px 14px;font-size:15px;line-height:1.5;outline:none}" +
      ".spb-field:focus{border-color:var(--spb-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--spb-accent) 22%,transparent)}" +
      ".spb-field::placeholder{color:var(--spb-muted)}" +
      ".spb-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;flex-wrap:wrap}" +
      ".spb-plans{display:flex;gap:8px;flex-wrap:wrap}" +
      ".spb-plan{cursor:pointer;border:1px solid var(--spb-border);background:var(--spb-chip);color:var(--spb-fg);" +
        "border-radius:999px;padding:7px 14px;font-size:13px;line-height:1;display:inline-flex;gap:6px;align-items:center;user-select:none}" +
      ".spb-plan small{color:var(--spb-muted);font-size:12px}" +
      ".spb-plan.on{border-color:var(--spb-accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--spb-accent) 22%,transparent)}" +
      ".spb-go{cursor:pointer;border:0;border-radius:12px;background:var(--spb-accent);color:var(--spb-btn-fg);font-size:15px;font-weight:600;" +
        "padding:12px 22px;display:inline-flex;align-items:center;gap:8px;margin-left:auto;transition:filter .15s}" +
      ".spb-go:hover{filter:brightness(1.08)}" +
      ".spb-go:disabled{opacity:.6;cursor:default}" +
      ".spb-err{display:none;margin-top:10px;font-size:13px;color:#e5484d}" +
      ".spb-err a{color:inherit;font-weight:600}" +
      ".spb-spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spbspin .7s linear infinite}" +
      "@keyframes spbspin{to{transform:rotate(360deg)}}" +
      // ---- popup ----
      ".spb-overlay{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;" +
        "background:var(--spb-overlay);font-family:var(--spb-font);opacity:0;transition:opacity .18s ease}" +
      ".spb-overlay.on{opacity:1}" +
      ".spb-modal{box-sizing:border-box;width:100%;max-width:420px;background:var(--spb-modal-bg);color:var(--spb-fg);" +
        "border:1px solid var(--spb-border);border-radius:var(--spb-radius);padding:24px;position:relative;" +
        "box-shadow:0 24px 70px rgba(0,0,0,.5);transform:translateY(8px) scale(.98);transition:transform .18s ease}" +
      ".spb-overlay.on .spb-modal{transform:none}" +
      ".spb-modal *{box-sizing:border-box;font-family:inherit}" +
      ".spb-close{position:absolute;top:12px;right:12px;width:30px;height:30px;border:0;border-radius:8px;cursor:pointer;" +
        "background:transparent;color:var(--spb-muted);font-size:20px;line-height:1;display:flex;align-items:center;justify-content:center}" +
      ".spb-close:hover{background:var(--spb-chip);color:var(--spb-fg)}" +
      ".spb-title{margin:0 30px 4px 0;font-size:19px;font-weight:700;letter-spacing:-.01em}" +
      ".spb-sub{margin:0 0 4px;font-size:13.5px;color:var(--spb-muted);line-height:1.45}" +
      ".spb-context{margin:10px 0 16px;font-size:12.5px;color:var(--spb-muted);background:var(--spb-input-bg);border:1px solid var(--spb-border);" +
        "border-radius:10px;padding:9px 12px;line-height:1.4;max-height:64px;overflow:auto;word-break:break-word}" +
      ".spb-context b{color:var(--spb-fg);font-weight:600}" +
      ".spb-field-group{display:flex;flex-direction:column;gap:5px;margin-bottom:12px}" +
      ".spb-label{font-size:12.5px;font-weight:600;color:var(--spb-fg)}" +
      ".spb-input{display:block;width:100%;border:1px solid var(--spb-border);border-radius:10px;background:var(--spb-input-bg);" +
        "color:var(--spb-fg);padding:11px 13px;font-size:15px;line-height:1.4;outline:none;transition:border-color .12s,box-shadow .12s}" +
      ".spb-input:focus{border-color:var(--spb-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--spb-accent) 22%,transparent)}" +
      ".spb-input::placeholder{color:var(--spb-muted)}" +
      ".spb-tos{display:flex;align-items:flex-start;gap:8px;font-size:12.5px;line-height:1.4;color:var(--spb-muted);cursor:pointer;margin-bottom:14px}" +
      ".spb-tos input{margin-top:2px}" +
      ".spb-tos a{color:var(--spb-accent)}" +
      ".spb-submit{width:100%;justify-content:center;margin-left:0}" +
      ".spb-modal-err{display:none;margin:0 0 12px;font-size:13px;color:#e5484d;line-height:1.4}" +
      ".spb-modal-err a{color:inherit;font-weight:600;cursor:pointer}" +
      ".spb-foot{margin-top:14px;text-align:center;font-size:12.5px}" +
      ".spb-login-link{color:var(--spb-muted);text-decoration:underline;cursor:pointer}" +
      ".spb-checkout-link{display:block;margin-top:8px;color:var(--spb-muted);text-decoration:underline;cursor:pointer;font-size:12px}" +
      "@media(max-width:480px){.spb{padding:14px}.spb-go{width:100%;justify-content:center}.spb-modal{padding:20px}}";

    var wrap = document.createElement("div");
    wrap.className = "spb";

    var field = document.createElement("textarea");
    field.className = "spb-field";
    field.placeholder = cfg.placeholder;
    field.maxLength = cfg.maxLen;
    field.rows = 3;

    var row = document.createElement("div");
    row.className = "spb-row";

    var selectedPid = cfg.pid;
    if (cfg.plans.length > 1) {
      var plansBox = document.createElement("div");
      plansBox.className = "spb-plans";
      cfg.plans.forEach(function (p, i) {
        var chip = document.createElement("span");
        chip.className = "spb-plan" + (i === 0 ? " on" : "");
        chip.setAttribute("role", "radio");
        chip.setAttribute("tabindex", "0");
        chip.textContent = String(p.label || ("Plan " + p.pid));
        if (p.price) { var pr = document.createElement("small"); pr.textContent = String(p.price); chip.appendChild(pr); }
        function pick() {
          selectedPid = parseInt(p.pid, 10);
          Array.prototype.forEach.call(plansBox.children, function (el) { el.className = "spb-plan"; });
          chip.className = "spb-plan on";
        }
        chip.addEventListener("click", pick);
        chip.addEventListener("keydown", function (ev) { if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); pick(); } });
        plansBox.appendChild(chip);
      });
      row.appendChild(plansBox);
    }

    var go = document.createElement("button");
    go.type = "button";
    go.className = "spb-go";
    var goLabel = document.createElement("span");
    goLabel.textContent = cfg.button;
    var goArrow = document.createElement("span");
    goArrow.textContent = "\\u2192";
    go.appendChild(goLabel);
    go.appendChild(goArrow);
    row.appendChild(go);

    var err = document.createElement("div");
    err.className = "spb-err";

    function fail(msg) {
      err.textContent = msg; err.style.display = "block";
      go.disabled = false; goArrow.className = ""; goArrow.textContent = "\\u2192";
    }
    function goBusy(busy) {
      go.disabled = busy;
      if (busy) { goArrow.textContent = ""; goArrow.className = "spb-spin"; }
      else { goArrow.className = ""; goArrow.textContent = "\\u2192"; }
    }

    function submitClassic() {
      var prompt = field.value.trim();
      if (!prompt) { field.focus(); return; }
      if (go.disabled) return;
      err.style.display = "none"; goBusy(true);
      var xhr = new XMLHttpRequest();
      xhr.open("POST", ENDPOINT, true);
      xhr.setRequestHeader("Content-Type", "application/json");
      xhr.onload = function () {
        var body = null;
        try { body = JSON.parse(xhr.responseText); } catch (e) {}
        if (xhr.status >= 200 && xhr.status < 300 && body && body.ok && body.redirect) { window.location.href = body.redirect; return; }
        if (body && body.error === "rate_limited") fail("Too many attempts right now \\u2014 please try again in a few minutes.");
        else fail("Something went wrong \\u2014 please try again.");
      };
      xhr.onerror = function () { fail("Could not reach the order system \\u2014 please try again."); };
      xhr.send(JSON.stringify({ prompt: prompt, pid: selectedPid }));
    }

    // ---- Built-in sign-up popup (EXPRESS on, not headless) ----
    var openModal = null;
    if (EXPRESS && !headless) {
      var overlay = document.createElement("div");
      overlay.className = "spb-overlay";
      var modal = document.createElement("div");
      modal.className = "spb-modal";
      modal.setAttribute("role", "dialog");
      modal.setAttribute("aria-modal", "true");
      overlay.appendChild(modal);

      var closeBtn = document.createElement("button");
      closeBtn.type = "button"; closeBtn.className = "spb-close";
      closeBtn.setAttribute("aria-label", "Close"); closeBtn.textContent = "\\u00d7";
      modal.appendChild(closeBtn);

      var title = document.createElement("h2");
      title.className = "spb-title"; title.textContent = cfg.expressTitle;
      title.id = "spb-title-" + Math.round(Math.random() * 1e9).toString(36);
      modal.setAttribute("aria-labelledby", title.id);
      modal.appendChild(title);
      var sub = document.createElement("p");
      sub.className = "spb-sub"; sub.textContent = cfg.expressSubtitle;
      modal.appendChild(sub);

      var context = document.createElement("div");
      context.className = "spb-context";
      modal.appendChild(context);

      var mErr = document.createElement("div");
      mErr.className = "spb-modal-err";
      modal.appendChild(mErr);

      function fieldGroup(labelText, input) {
        var g = document.createElement("div"); g.className = "spb-field-group";
        var lab = document.createElement("label"); lab.className = "spb-label"; lab.textContent = labelText;
        var id = "spb-" + Math.round(Math.random() * 1e9).toString(36);
        input.id = id; lab.setAttribute("for", id);
        g.appendChild(lab); g.appendChild(input);
        return g;
      }

      var emailField = document.createElement("input");
      emailField.type = "email"; emailField.className = "spb-input";
      emailField.placeholder = cfg.emailPlaceholder; emailField.autocomplete = "email";
      modal.appendChild(fieldGroup(cfg.emailLabel, emailField));

      var passField = document.createElement("input");
      passField.type = "password"; passField.className = "spb-input";
      passField.placeholder = cfg.passwordPlaceholder; passField.autocomplete = "new-password";
      passField.setAttribute("minlength", String(MIN_PASSWORD));
      modal.appendChild(fieldGroup(cfg.passwordLabel, passField));

      var tosCheckbox = null;
      if (TOS_URL) {
        var tosRow = document.createElement("label"); tosRow.className = "spb-tos";
        tosCheckbox = document.createElement("input"); tosCheckbox.type = "checkbox";
        var tosText = document.createElement("span");
        tosText.appendChild(document.createTextNode(cfg.termsText + " "));
        var tosLink = document.createElement("a");
        tosLink.href = TOS_URL; tosLink.target = "_blank"; tosLink.rel = "noopener"; tosLink.textContent = "\\u2197";
        tosText.appendChild(tosLink);
        tosRow.appendChild(tosCheckbox); tosRow.appendChild(tosText);
        modal.appendChild(tosRow);
      }

      var submitBtn = document.createElement("button");
      submitBtn.type = "button"; submitBtn.className = "spb-go spb-submit";
      var submitLabel = document.createElement("span"); submitLabel.textContent = cfg.expressButton;
      submitBtn.appendChild(submitLabel);
      modal.appendChild(submitBtn);

      var foot = document.createElement("div"); foot.className = "spb-foot";
      var loginLink = document.createElement("a");
      loginLink.href = LOGIN_URL; loginLink.className = "spb-login-link"; loginLink.textContent = cfg.loginText;
      foot.appendChild(loginLink);
      var checkoutLink = document.createElement("a");
      checkoutLink.href = "#"; checkoutLink.className = "spb-checkout-link"; checkoutLink.textContent = "Prefer the regular checkout?";
      foot.appendChild(checkoutLink);
      modal.appendChild(foot);

      var lastFocus = null;
      // Bumped on every close and every new submit. An in-flight express
      // response captured its generation at send time and must NOT navigate
      // (or mutate the modal) if the visitor has since dismissed it or started
      // over — otherwise a slow signup response hijacks a page the visitor
      // already moved on from, and "checkout instead" (which closes first)
      // can't be raced by the express reply.
      var gen = 0;
      var removeTimer = null;
      function focusables() { return modal.querySelectorAll("button, input, a[href]"); }
      function trap(ev) {
        if (ev.key === "Escape") { ev.preventDefault(); closeModal(); return; }
        if (ev.key !== "Tab") return;
        var f = focusables(); if (!f.length) return;
        var first = f[0], last = f[f.length - 1];
        if (ev.shiftKey && root.activeElement === first) { ev.preventDefault(); last.focus(); }
        else if (!ev.shiftKey && root.activeElement === last) { ev.preventDefault(); first.focus(); }
      }
      var loginMode = false;
      function submitReset() { submitBtn.disabled = false; submitLabel.textContent = loginMode ? "Log in" : cfg.expressButton; }
      function mErrText(msg) { mErr.textContent = msg; mErr.style.display = "block"; submitReset(); }
      function mErrLink(prefix, linkText, href, onClick) {
        mErr.textContent = ""; mErr.appendChild(document.createTextNode(prefix + " "));
        var a = document.createElement("a"); a.href = href || "#"; a.textContent = linkText;
        if (onClick) a.addEventListener("click", function (ev) { ev.preventDefault(); onClick(); });
        mErr.appendChild(a); mErr.appendChild(document.createTextNode("."));
        mErr.style.display = "block"; submitReset();
      }
      function loginState(email) {
        loginMode = true;
        title.textContent = "Welcome back";
        sub.textContent = "You already have an account \\u2014 sign in to keep building.";
        mErr.style.display = "none";
        var g = passField.parentNode; if (g) g.style.display = "none";
        if (tosCheckbox && tosCheckbox.parentNode) tosCheckbox.parentNode.style.display = "none";
        emailField.value = email || emailField.value; emailField.readOnly = true;
        submitBtn.disabled = false; submitLabel.textContent = "Log in";
        loginLink.style.display = "none";
      }

      closeModal = function () {
        gen++; // invalidate any in-flight express response
        overlay.classList.remove("on");
        document.removeEventListener("keydown", trap, true);
        if (removeTimer) { clearTimeout(removeTimer); }
        removeTimer = setTimeout(function () {
          removeTimer = null;
          if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 200);
        try { document.documentElement.style.overflow = ""; } catch (e) {}
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
      };

      function submitExpress() {
        if (loginMode) { window.location.href = LOGIN_URL; return; }
        var email = emailField.value.trim();
        var password = passField.value;
        if (!email) { emailField.focus(); return; }
        if (!password || password.length < MIN_PASSWORD) { mErrText("Please use a password of at least " + MIN_PASSWORD + " characters."); passField.focus(); return; }
        if (TOS_URL && (!tosCheckbox || !tosCheckbox.checked)) { mErrText("Please agree to the terms to continue."); return; }
        if (submitBtn.disabled) return;
        mErr.style.display = "none"; submitBtn.disabled = true; submitLabel.textContent = "Creating your account\\u2026";
        var myGen = ++gen;
        emit("prompt", { prompt: field.value.trim(), pid: selectedPid });
        apiSubmit({ prompt: field.value.trim(), pid: selectedPid, email: email, password: password, tos: !!(tosCheckbox && tosCheckbox.checked) })
          .then(function (res) {
            if (myGen !== gen) return; // dismissed / superseded while in flight — don't hijack
            if (res.ok && res.redirect) { window.location.href = res.redirect; return; }
            if (res.error === "account_exists") loginState(email);
            else if (res.error === "rate_limited") mErrText("Too many attempts right now \\u2014 please try again in a few minutes.");
            else if (res.error === "invalid_email") mErrText("That email doesn't look right \\u2014 please check it.");
            else if (res.error === "weak_password") mErrText("Please use a password of at least " + MIN_PASSWORD + " characters.");
            else if (res.error === "tos_required") mErrText("Please agree to the terms to continue.");
            else if (res.error === "network_error") mErrLink("Couldn't reach the sign-up service \\u2014 try again, or", "checkout instead", "#", function () { closeModal(); submitClassic(); });
            else mErrLink("Something went wrong \\u2014 try again, or", "checkout instead", "#", function () { closeModal(); submitClassic(); });
          });
      }

      submitBtn.addEventListener("click", submitExpress);
      emailField.addEventListener("keydown", function (ev) { if (ev.key === "Enter") { ev.preventDefault(); submitExpress(); } });
      passField.addEventListener("keydown", function (ev) { if (ev.key === "Enter") { ev.preventDefault(); submitExpress(); } });
      closeBtn.addEventListener("click", closeModal);
      overlay.addEventListener("click", function (ev) { if (ev.target === overlay) closeModal(); });
      checkoutLink.addEventListener("click", function (ev) { ev.preventDefault(); closeModal(); submitClassic(); });

      openModal = function (promptText) {
        gen++; // a fresh open supersedes any dismissed in-flight response
        if (removeTimer) { clearTimeout(removeTimer); removeTimer = null; } // cancel a pending detach so a quick close→reopen can't strip the reopened modal
        var ctxPrompt = (promptText || "").trim();
        context.textContent = "";
        var lab = document.createElement("b"); lab.textContent = "Building: ";
        context.appendChild(lab);
        context.appendChild(document.createTextNode(ctxPrompt || "your app"));
        lastFocus = root.activeElement;
        if (!overlay.parentNode) root.appendChild(overlay);
        try { document.documentElement.style.overflow = "hidden"; } catch (e) {}
        document.removeEventListener("keydown", trap, true); // never stack duplicate traps
        document.addEventListener("keydown", trap, true);
        requestAnimationFrame(function () { overlay.classList.add("on"); emailField.focus(); });
      };
      // First non-headless widget on the page owns the programmatic .open(),
      // matching the first-wins singleton for .submit()/.on(). A page with
      // several widgets should drive extras via their own DOM, not this API.
      if (typeof window.SwarmzPromptBox.open !== "function" || !window.SwarmzPromptBox._hasModal) {
        window.SwarmzPromptBox.open = openModal;
        window.SwarmzPromptBox._hasModal = true;
      }
    }

    function onGoClick() {
      var prompt = field.value.trim();
      if (!EXPRESS) { submitClassic(); return; }
      if (!prompt) { field.focus(); return; }
      if (headless) { emit("prompt", { prompt: prompt, pid: selectedPid }); return; }
      openModal(prompt);
    }

    go.addEventListener("click", onGoClick);
    field.addEventListener("keydown", function (ev) { if (ev.key === "Enter" && !ev.shiftKey) { ev.preventDefault(); onGoClick(); } });

    wrap.appendChild(field);
    wrap.appendChild(row);
    wrap.appendChild(err);
    root.appendChild(style);
    root.appendChild(wrap);
  }
  var closeModal = function () {};

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
JS;
exit;
