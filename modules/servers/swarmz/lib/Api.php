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
    const VERSION = '1.3.0';

    /** Default base URL (swarmz public API). Server config can override. */
    const DEFAULT_BASE_URL = 'https://api.swarmz.net';

    /** Function path prefix (Supabase edge functions). */
    const FUNCTIONS_PATH = '/functions/v1';

    /** Default request timeout. */
    const TIMEOUT_SECONDS = 30;

    /** Default connection timeout. */
    const CONNECT_TIMEOUT_SECONDS = 10;

    /** @var string Base URL without trailing slash. */
    private $baseUrl;

    /** @var string The sk_live_ bearer key (raw — never logged). */
    private $apiKey;

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
        return $this->postPlatform('platform-plan-refresh', $body);
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
     * Perform the cURL request and translate the response.
     *
     * @param string      $method
     * @param string      $url
     * @param string|null $body JSON-encoded body or null
     * @return array{statusCode:int, body:array}
     */
    private function execute(string $method, string $url, $body): array
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
