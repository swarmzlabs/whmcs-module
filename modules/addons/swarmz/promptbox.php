<?php
/**
 * Swarmz Prompt Box — public endpoint.
 *
 *   GET  ?a=js      → the embeddable widget script (host pastes ONE <script>
 *                     tag on any page: plain HTML, WordPress, any builder).
 *   POST ?a=intent  → stores a visitor's prompt as an intent, returns the
 *                     cart redirect carrying an opaque token.
 *
 * This file is the ONLY public surface of the prompt-box feature. It boots
 * WHMCS (for DB + SystemURL) but requires no authentication: the stored
 * prompt is inert visitor input — it only ever reaches the Swarmz platform
 * attached to a real provisioned service, through the host's own API key.
 * Abuse is bounded by per-IP rate limiting, a size cap, product allow-listing
 * (swarmz-module products only) and 30-day retention.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

use WHMCS\Module\Addon\Swarmz\PromptBox;

require __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib/PromptBox.php';

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

// ---------------------------------------------------------------------------
// a=js — the widget. Served with long-ish caching; all per-page options are
// data-* attributes on the embedding <script> tag, so the JS itself is static.
// ---------------------------------------------------------------------------
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$endpoint = rtrim(PromptBox::systemUrl(), '/') . '/modules/addons/swarmz/promptbox.php?a=intent';
$endpointJson = json_encode($endpoint, JSON_UNESCAPED_SLASHES);

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
 *           data-plans='[{"pid":12,"label":"Starter","price":"$9/mo"},{"pid":13,"label":"Pro","price":"$29/mo"}]'
 *           async></script>
 *
 * With data-plans the visitor picks a plan inline; with only data-pid the
 * widget submits straight into that product. Renders where the tag sits, or
 * into the element matching data-target (a CSS selector) when given.
 */
(function () {
  "use strict";
  var script = document.currentScript;
  if (!script) return;
  var ENDPOINT = {$endpointJson};

  var cfg = {
    pid: parseInt(script.getAttribute("data-pid") || "0", 10) || 0,
    button: script.getAttribute("data-button") || "Start building",
    placeholder: script.getAttribute("data-placeholder") || "Describe the app you want to build\\u2026",
    theme: (script.getAttribute("data-theme") || "auto").toLowerCase(),
    accent: script.getAttribute("data-accent") || "#4f46e5",
    target: script.getAttribute("data-target") || "",
    maxLen: 10000,
    plans: []
  };
  try {
    var plansRaw = script.getAttribute("data-plans");
    if (plansRaw) {
      var parsed = JSON.parse(plansRaw);
      if (Array.isArray(parsed)) {
        cfg.plans = parsed.filter(function (p) {
          return p && typeof p === "object" && parseInt(p.pid, 10) > 0;
        });
      }
    }
  } catch (e) { /* bad data-plans JSON → single-product mode */ }
  if (!cfg.pid && cfg.plans.length) cfg.pid = parseInt(cfg.plans[0].pid, 10);
  if (!cfg.pid) return; // nothing to order into — refuse quietly

  function mountHost() {
    if (cfg.target) {
      var t = document.querySelector(cfg.target);
      if (t) return t;
    }
    var host = document.createElement("div");
    script.parentNode.insertBefore(host, script.nextSibling);
    return host;
  }

  function darkPreferred() {
    if (cfg.theme === "dark") return true;
    if (cfg.theme === "light") return false;
    return window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches;
  }

  function boot() {
    var host = mountHost();
    var root = host.attachShadow ? host.attachShadow({ mode: "open" }) : host;
    var dark = darkPreferred();

    var c = {
      bg: dark ? "#101014" : "#ffffff",
      text: dark ? "#f4f4f6" : "#16161a",
      sub: dark ? "rgba(244,244,246,.55)" : "rgba(22,22,26,.55)",
      border: dark ? "rgba(255,255,255,.12)" : "rgba(22,22,26,.12)",
      field: dark ? "rgba(255,255,255,.05)" : "rgba(22,22,26,.03)",
      chip: dark ? "rgba(255,255,255,.06)" : "rgba(22,22,26,.05)"
    };

    var style = document.createElement("style");
    style.textContent =
      ":host{all:initial}" +
      ".spb{box-sizing:border-box;font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;" +
        "background:" + c.bg + ";color:" + c.text + ";border:1px solid " + c.border + ";border-radius:16px;" +
        "padding:18px;max-width:640px;box-shadow:0 8px 30px rgba(0,0,0," + (dark ? ".45" : ".08") + ")}" +
      ".spb *{box-sizing:border-box;font-family:inherit}" +
      ".spb-field{display:block;width:100%;min-height:96px;resize:vertical;border:1px solid " + c.border + ";" +
        "border-radius:12px;background:" + c.field + ";color:" + c.text + ";padding:12px 14px;font-size:15px;line-height:1.5;outline:none}" +
      ".spb-field:focus{border-color:" + cfg.accent + ";box-shadow:0 0 0 3px " + cfg.accent + "33}" +
      ".spb-field::placeholder{color:" + c.sub + "}" +
      ".spb-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;flex-wrap:wrap}" +
      ".spb-plans{display:flex;gap:8px;flex-wrap:wrap}" +
      ".spb-plan{cursor:pointer;border:1px solid " + c.border + ";background:" + c.chip + ";color:" + c.text + ";" +
        "border-radius:999px;padding:7px 14px;font-size:13px;line-height:1;display:inline-flex;gap:6px;align-items:center;user-select:none}" +
      ".spb-plan small{color:" + c.sub + ";font-size:12px}" +
      ".spb-plan.on{border-color:" + cfg.accent + ";box-shadow:0 0 0 2px " + cfg.accent + "33}" +
      ".spb-go{cursor:pointer;border:0;border-radius:12px;background:" + cfg.accent + ";color:#fff;font-size:15px;font-weight:600;" +
        "padding:12px 22px;display:inline-flex;align-items:center;gap:8px;margin-left:auto;transition:filter .15s}" +
      ".spb-go:hover{filter:brightness(1.08)}" +
      ".spb-go:disabled{opacity:.6;cursor:default}" +
      ".spb-err{display:none;margin-top:10px;font-size:13px;color:#e5484d}" +
      ".spb-spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;" +
        "animation:spbspin .7s linear infinite}" +
      "@keyframes spbspin{to{transform:rotate(360deg)}}" +
      "@media(max-width:480px){.spb{padding:14px}.spb-go{width:100%;justify-content:center}}";

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
        if (p.price) {
          var pr = document.createElement("small");
          pr.textContent = String(p.price);
          chip.appendChild(pr);
        }
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
      err.textContent = msg;
      err.style.display = "block";
      go.disabled = false;
      goArrow.className = "";
      goArrow.textContent = "\\u2192";
    }

    function submit() {
      var prompt = field.value.trim();
      if (!prompt) { field.focus(); return; }
      if (go.disabled) return;
      err.style.display = "none";
      go.disabled = true;
      goArrow.textContent = "";
      goArrow.className = "spb-spin";
      var xhr = new XMLHttpRequest();
      xhr.open("POST", ENDPOINT, true);
      xhr.setRequestHeader("Content-Type", "application/json");
      xhr.onload = function () {
        var body = null;
        try { body = JSON.parse(xhr.responseText); } catch (e) { /* noop */ }
        if (xhr.status >= 200 && xhr.status < 300 && body && body.ok && body.redirect) {
          window.location.href = body.redirect;
          return;
        }
        if (body && body.error === "rate_limited") {
          fail("Too many attempts right now \\u2014 please try again in a few minutes.");
        } else {
          fail("Something went wrong \\u2014 please try again.");
        }
      };
      xhr.onerror = function () { fail("Could not reach the order system \\u2014 please try again."); };
      xhr.send(JSON.stringify({ prompt: prompt, pid: selectedPid }));
    }

    go.addEventListener("click", submit);
    field.addEventListener("keydown", function (ev) {
      if (ev.key === "Enter" && !ev.shiftKey) { ev.preventDefault(); submit(); }
    });

    wrap.appendChild(field);
    wrap.appendChild(row);
    wrap.appendChild(err);
    root.appendChild(style);
    root.appendChild(wrap);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
JS;
exit;
