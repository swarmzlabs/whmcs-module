<?php
/**
 * Swarmz WHMCS Module - HTTP API Client
 *
 * Thin client around the swarmz public platform API. Sends Bearer-auth'd
 * JSON requests, parses JSON responses, and translates non-2xx bodies into
 * SwarmzApiException with the {error, reason} pair extracted.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Server\Swarmz;

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/Exceptions.php';

class Api
{
    /** Module version, used in User-Agent and bug reports. */
    const VERSION = '1.24.0';

    /** Default base URL (swarmz public API). Server config can override. */
    const DEFAULT_BASE_URL = 'https://api.swarmz.net';

    /** Function path prefix (Supabase edge functions). */
    const FUNCTIONS_PATH = '/functions/v1';

    /** Default request timeout. */
    const TIMEOUT_SECONDS = 30;

    /** Default connection timeout. */
    const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Transient-failure retries (v1.9.0). Every platform endpoint this module
     * calls is idempotent server-side (create keys on external_ref, suspend/
     * terminate are idempotent-by-state, sso/usage are reads or fresh mints),
     * so retrying a request that never produced a definitive answer is always
     * safe. We retry ONLY:
     *   - cURL transport failures (DNS blip, TLS reset, timeout), and
     *   - 5xx / edge-gateway statuses (502/503/504/522/524) — cold starts and
     *     brief platform deploys.
     * Definitive 4xx answers are NEVER retried. This is what turns a one-in-a-
     * hundred "Network error talking to swarmz API" SSO click into a non-event.
     */
    const MAX_RETRIES = 2;

    /** Base backoff between retries (milliseconds); doubles per attempt. */
    const RETRY_BACKOFF_MS = 300;

    /** @var string Base URL without trailing slash. */
    private $baseUrl;

    /** @var string The sk_live_ bearer key (raw — never logged). */
    private $apiKey;

    /** @var array<int,array>|null Per-instance cache of the listPlans() result. */
    private $plansCache = null;

    /** @var array|null Per-instance cache of platform-plans credit_packs. */
    private $creditPacksCache = null;

    /**
     * @param string $apiKey  e.g. "sk_live_abc123..."
     * @param string $baseUrl e.g. "https://api.swarmz.net" (no trailing /)
     */
    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE_URL)
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new SwarmzConfigException(
                'Swarmz API key is missing. Set the sk_live_… key in the WHMCS server Password field.'
            );
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/');
    }

    /**
     * Convenience POST to a single-purpose platform endpoint.
     *
     * @param string $endpoint e.g. "platform-create" (no leading slash)
     * @param array  $body     Request body (will be JSON-encoded)
     * @return array{statusCode:int, body:array} tuple
     */
    public function postPlatform(string $endpoint, array $body): array
    {
        $path = self::FUNCTIONS_PATH . '/' . ltrim($endpoint, '/');
        return $this->post($path, $body);
    }

    /**
     * Convenience GET to a single-purpose platform endpoint.
     *
     * @param string $endpoint e.g. "platform-usage"
     * @param array  $query    Querystring params
     * @return array{statusCode:int, body:array} tuple
     */
    public function getPlatform(string $endpoint, array $query = []): array
    {
        $path = self::FUNCTIONS_PATH . '/' . ltrim($endpoint, '/');
        return $this->get($path, $query);
    }

    /**
     * Roll the workspace's monthly credit cycle (reset/rollover) at a billing
     * boundary. Maps to POST /functions/v1/platform-plan-refresh.
     *
     * The host owns the billing cycle, so we drive the reset explicitly from
     * WHMCS (on ChangePackage / renewal) rather than trusting a swarmz-side
     * wall-clock cron. The call is idempotent per (tenant, cycle_anchor): a
     * retry for the same anchor is a no-op server-side.
     *
     * Body contract (reseller-production-buildout.md §3 W1.5):
     *   { tenant_id | external_ref, cycle_anchor: "<ISO date>" }
     * Either tenant_id or external_ref resolves the workspace; we send whichever
     * we have (tenant_id is preferred and unambiguous).
     *
     * @param string      $refOrId     A swarmz tenant_id (UUID) OR a WHMCS
     *                                  external_ref ("whmcs:<serviceid>").
     * @param string      $cycleAnchor ISO-8601 date (e.g. "2026-06-01") marking
     *                                  the start of the new billing cycle.
     * @param bool        $isTenantId  When true (default), $refOrId is sent as
     *                                  tenant_id; when false, as external_ref.
     * @return array{statusCode:int, body:array}
     */
    public function planRefresh(string $refOrId, string $cycleAnchor, bool $isTenantId = true): array
    {
        $body = [
            'cycle_anchor' => $cycleAnchor,
        ];
        if ($isTenantId) {
            $body['tenant_id'] = $refOrId;
        } else {
            $body['external_ref'] = $refOrId;
        }
        // v1.23.0: report this install's SystemURL so the platform can deep-link
        // customers back into this WHMCS for upgrades (see upgrade.php).
        return $this->postPlatform('platform-plan-refresh', self::withPortal($body));
    }

    /**
     * This install's billing-portal descriptor, sent alongside provisioning and
     * plan-refresh calls so the platform can form upgrade deep links into this
     * WHMCS without any host configuration: `{kind:'whmcs', url:<SystemURL>}`.
     * Null when SystemURL is unset or not https (the platform ignores those).
     *
     * @return array{kind:string,url:string}|null
     */
    public static function billingPortal(): ?array
    {
        $base = '';
        try {
            if (class_exists('\\WHMCS\\Config\\Setting')) {
                $base = trim((string) \WHMCS\Config\Setting::getValue('SystemURL'));
            }
        } catch (\Throwable $e) {
            $base = '';
        }
        if ($base === '' && isset($GLOBALS['CONFIG']['SystemURL'])) {
            $base = trim((string) $GLOBALS['CONFIG']['SystemURL']);
        }
        $base = rtrim($base, '/');
        if ($base === '' || stripos($base, 'https://') !== 0) {
            return null;
        }
        return ['kind' => 'whmcs', 'url' => $base];
    }

    /**
     * Attach this install's billing-portal descriptor to a request body
     * (v1.24.0). Every routine platform call the module makes — the daily
     * usage read, plan lists, customer/admin SSO mints, Test Connection,
     * provisioning, plan refresh — rides the portal along, so a host registers
     * (and keeps current) the WHMCS URL behind the editor's upgrade deep links
     * without configuring anything and without waiting for a new order.
     * No-op when SystemURL is unset/non-https or the body already carries one.
     *
     * @param array $body
     * @return array
     */
    public static function withPortal(array $body): array
    {
        $portal = self::billingPortal();
        if ($portal !== null && !isset($body['billing_portal'])) {
            $body['billing_portal'] = $portal;
        }
        return $body;
    }

    /**
     * Register this install's billing portal with the platform right now
     * (v1.24.0). Used on addon activation/upgrade so the upgrade deep links
     * light up the moment a host updates, instead of after the next cron or
     * customer login. Rides on platform-plans (key-authed, cheap, idempotent).
     *
     * @return bool True when a portal descriptor was sent.
     */
    public function registerBillingPortal(): bool
    {
        if (self::billingPortal() === null) {
            return false;
        }
        $this->listPlans(true);
        return true;
    }

    /**
     * Exchange a one-time upgrade intent (minted by the platform for a signed-in
     * customer and carried through the browser to this install's upgrade.php)
     * for the customer's service reference. Maps to
     * POST /functions/v1/platform-upgrade-intent.
     *
     * @return array{statusCode:int, body:array}
     */
    public function verifyUpgradeIntent(string $intent): array
    {
        return $this->postPlatform('platform-upgrade-intent', self::withPortal(['intent' => $intent]));
    }

    /**
     * List the reseller account's named plans. Maps to POST
     * /functions/v1/platform-plans (key-authed, empty body).
     *
     * A "named plan" bundles a complete set of entitlements behind a stable
     * `code` (resolved server-side when passed as plan_code to platform-create /
     * platform-plan); picking a plan by name is the only way to provision.
     *
     * Response contract (platform-plans/index.ts):
     *   { ok: true, plans: [ {
     *       code, display_name, monthly_credits, free_credits_per_day,
     *       monthly_credit_cap, rollover_months, max_projects,
     *       max_published_projects, max_custom_domains, custom_domains_enabled,
     *       max_compute_size, cloud_budget_cap, price_cents, currency
     *   }, … ] }
     *
     * Tolerant of an as-yet-undeployed endpoint: the CALLER decides how to
     * degrade (the config-options dropdown and the Console swallow a failure and
     * show an empty list / note). This method itself just returns the plans
     * array on success and propagates the typed exception otherwise.
     *
     * The result is cached for the lifetime of this Api instance (this method
     * may be called several times in one request — e.g. ConfigOptions render +
     * a subsequent lookup), so repeated calls cost a single round-trip.
     *
     * @param bool $forceRefresh When true, bypass the per-instance cache.
     * @return array<int,array> The plans array (possibly empty).
     */
    public function listPlans(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && $this->plansCache !== null) {
            return $this->plansCache;
        }
        // Carries the billing portal (v1.24.0) — plan lists are the module's
        // most frequent key-authed call (console, product config, daily cron).
        $result = $this->postPlatform('platform-plans', self::withPortal([]));
        $body = $result['body'];
        $plans = (isset($body['plans']) && is_array($body['plans'])) ? array_values($body['plans']) : [];
        $this->plansCache = $plans;
        // Same response carries the pack catalog — cache it so a subsequent
        // listCreditPacks() in the same request costs no extra round-trip.
        $this->creditPacksCache = (isset($body['credit_packs']) && is_array($body['credit_packs']))
            ? array_values($body['credit_packs'])
            : [];
        return $plans;
    }

    /**
     * The partner's credit-pack catalog (platform-plans `credit_packs`):
     * active, unarchived packs the partner defined in the Swarmz dashboard's
     * plan builder. Each row: code, name, description, credits, price_cents,
     * currency, billing_cycle ('onetime'|'monthly').
     *
     * The catalog is the source of truth for pack mapping — the Console's
     * Credit Packs page offers these in a dropdown and caches code + credits
     * in the mapping table, so grants never depend on a live catalog read.
     * Same per-instance caching and degradation contract as listPlans().
     *
     * @param bool $forceRefresh When true, bypass the per-instance cache.
     * @return array<int,array> The packs array (possibly empty).
     */
    public function listCreditPacks(bool $forceRefresh = false): array
    {
        if (!$forceRefresh && $this->creditPacksCache !== null) {
            return $this->creditPacksCache;
        }
        $this->listPlans(true);
        return is_array($this->creditPacksCache) ? $this->creditPacksCache : [];
    }

    /**
     * Fetch the consolidated billing summary for the account (or a single
     * workspace when a ref is supplied). Maps to POST
     * /functions/v1/platform-billing-summary.
     *
     * NOTE ON AUTH (important): the deployed platform-billing-summary function
     * authenticates the *account owner's Supabase user JWT* — NOT the sk_live_
     * platform key this module uses. Called with the platform key it currently
     * returns 401 {error:"unauthorized", reason:"invalid_token"}. The admin
     * Console therefore treats a 401/403 here as "summary not available over the
     * key auth surface" and falls back to the key-authed platform-usage
     * aggregate. This method is implemented to the documented contract so it
     * lights up automatically if/when the endpoint gains key-auth (or a
     * key-authed sibling is added) — see the report notes.
     *
     * Response shape (from platform-billing-summary/index.ts):
     *   {
     *     ok: true,
     *     account: { id, name, slug, email },
     *     usage:   { credits_used, usd_credits, cloud_usd,
     *                period: { from, to, label },
     *                by_workspace: [{ workspace_id, credits_used, usd_credits, cloud_usd }] },
     *     upcoming: { amount_due_cents, currency, period_end, next_attempt } | null,
     *     upcoming_error?: string,
     *     card: { brand, last4, exp_month, exp_year } | null,
     *     card_on_file: bool,
     *     billing: { company, email, address, vat } | null,
     *     invoices: [{ id, stripe_invoice_id, status, amount_due_cents,
     *                  amount_paid_cents, currency, period_start, period_end,
     *                  hosted_invoice_url, paid_at, created_at }]
     *   }
     *
     * @param string|null $refOrId    Optional tenant_id to scope the summary to
     *                                 one workspace. Null = account-wide.
     * @param bool        $isTenantId When true (default) a non-null $refOrId is
     *                                sent as tenant_id; else as external_ref.
     * @return array{statusCode:int, body:array}
     */
    public function billingSummary(?string $refOrId = null, bool $isTenantId = true): array
    {
        $body = [];
        if ($refOrId !== null && $refOrId !== '') {
            if ($isTenantId) {
                $body['tenant_id'] = $refOrId;
            } else {
                $body['external_ref'] = $refOrId;
            }
        }
        return $this->postPlatform('platform-billing-summary', $body);
    }

    /**
     * POST $body (as JSON) to $path with Bearer auth.
     *
     * @return array{statusCode:int, body:array}
     */
    public function post(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new SwarmzTransportException('Failed to encode request body as JSON.');
        }
        return $this->execute('POST', $url, $payload);
    }

    /**
     * GET $path?query with Bearer auth.
     *
     * @return array{statusCode:int, body:array}
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $this->execute('GET', $url, null);
    }

    /**
     * Perform the request with transient-failure retries (see MAX_RETRIES).
     *
     * @param string      $method
     * @param string      $url
     * @param string|null $body JSON-encoded body or null
     * @return array{statusCode:int, body:array}
     */
    private function execute(string $method, string $url, $body): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                // 300ms, 600ms — long enough to ride out a cold start or a
                // deploy blip, short enough that an SSO click still feels
                // instant when the retry succeeds.
                usleep(self::RETRY_BACKOFF_MS * 1000 * $attempt);
            }
            try {
                return $this->executeOnce($method, $url, $body);
            } catch (SwarmzTransportException $e) {
                // Transport-level fault (cURL error / non-JSON body) — retry.
                $lastException = $e;
            } catch (SwarmzApiException $e) {
                $status = $e->getStatusCode();
                // Gateway/overload statuses are transient; anything else is a
                // definitive answer and must surface immediately.
                if (in_array($status, [500, 502, 503, 504, 522, 524], true)) {
                    $lastException = $e;
                } else {
                    throw $e;
                }
            }
        }

        throw $lastException;
    }

    /**
     * One cURL round-trip, translated. Split out of execute() so the retry
     * loop above stays readable.
     *
     * @param string      $method
     * @param string      $url
     * @param string|null $body JSON-encoded body or null
     * @return array{statusCode:int, body:array}
     */
    private function executeOnce(string $method, string $url, $body): array
    {
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: swarmz-whmcs/' . self::VERSION . ' (+https://swarmz.net)',
        ];

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($ch, $opts);

        $rawResponse = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno !== 0 || $rawResponse === false) {
            throw new SwarmzTransportException(
                sprintf('Network error talking to swarmz API: %s (errno %d)', $curlError, $curlErrno),
                0,
                'transport_error'
            );
        }

        // Try to decode JSON. swarmz endpoints always return JSON; if it's not, that's a transport-level fault.
        $decoded = json_decode((string) $rawResponse, true);
        if (!is_array($decoded)) {
            throw new SwarmzTransportException(
                sprintf('Unexpected non-JSON response from swarmz API (HTTP %d).', $statusCode),
                $statusCode,
                'invalid_response'
            );
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $errorCode = isset($decoded['error']) ? (string) $decoded['error'] : 'unknown_error';
            $reason = isset($decoded['reason']) ? (string) $decoded['reason'] : (isset($decoded['message']) ? (string) $decoded['message'] : '');
            $message = $reason !== '' ? sprintf('%s: %s', $errorCode, $reason) : $errorCode;
            throw new SwarmzApiException($message, $statusCode, $errorCode, $decoded);
        }

        return [
            'statusCode' => $statusCode,
            'body'       => $decoded,
        ];
    }

    /**
     * Return a redacted preview of the API key for logs/diagnostics.
     * Example: "sk_live_abc1********" (never expose full key).
     */
    public function maskedKey(): string
    {
        $key = $this->apiKey;
        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }
        return substr($key, 0, 12) . str_repeat('*', max(0, strlen($key) - 12));
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
