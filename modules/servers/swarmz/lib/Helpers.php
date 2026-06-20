<?php
/**
 * Swarmz WHMCS Module - Helpers
 *
 * Pure-function helpers: selected-plan resolution, named-plan fetch, external_ref
 * builder, custom-field readers/writers (auto-create the field on first use).
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Server\Swarmz;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Exceptions.php';

class Helpers
{
    /** Custom-field name used to store the swarmz tenant_id on the service. */
    const CUSTOM_FIELD_TENANT_ID = 'Swarmz Tenant ID';

    /** Custom-field name used to store the swarmz dashboard URL. */
    const CUSTOM_FIELD_DASHBOARD_URL = 'Swarmz Dashboard URL';

    /** Custom-field name used to cache the latest usage snapshot (JSON) for the client area. */
    const CUSTOM_FIELD_USAGE_CACHE = 'Swarmz Usage Cache';

    /** Default base URL used if the WHMCS server hostname is blank. */
    const DEFAULT_API_BASE_URL = 'https://api.swarmz.net';

    /** Companion addon module name — the Reseller Console — for host-side settings. */
    const ADDON_MODULE = 'swarmz';

    /**
     * Sentinel label for the "no plan selected" entry in the Plan dropdown.
     * Selecting it means no plan is chosen yet — provisioning is refused until a
     * real plan is picked.
     */
    const PLAN_NONE_LABEL = '— Select a plan —';

    /**
     * Build the WHMCS-side idempotency key passed to swarmz as `external_ref`.
     * Format: "whmcs:<serviceid>". A retried provision uses the same key.
     */
    public static function buildExternalRef($serviceId): string
    {
        return 'whmcs:' . (string) $serviceId;
    }

    /**
     * Resolve the billing-cycle anchor (ISO date, "YYYY-MM-DD") to pass to
     * /platform-plan-refresh.
     *
     * The anchor marks the start of the new credit cycle. The most faithful
     * value is the service's next-due-date (the date the just-paid/renewed
     * cycle runs to). Precedence:
     *   1. $params['nextduedate'] when WHMCS includes it in the module bag.
     *   2. tblhosting.nextduedate for the service.
     *   3. today (UTC) as a safe fallback so a refresh still fires.
     *
     * WHMCS stores dates as "YYYY-MM-DD"; a "0000-00-00" sentinel (free / one-
     * time products) is treated as absent.
     *
     * @param array $params
     * @param int   $serviceId
     * @return string ISO date, e.g. "2026-06-01"
     */
    public static function resolveCycleAnchor(array $params, int $serviceId): string
    {
        $candidate = '';

        if (isset($params['nextduedate']) && is_string($params['nextduedate'])) {
            $candidate = trim($params['nextduedate']);
        }

        if (!self::isUsableDate($candidate) && $serviceId > 0) {
            try {
                $row = Capsule::table('tblhosting')->where('id', $serviceId)->first(['nextduedate']);
                if ($row && isset($row->nextduedate)) {
                    $candidate = trim((string) $row->nextduedate);
                }
            } catch (\Throwable $e) {
                $candidate = '';
            }
        }

        if (self::isUsableDate($candidate)) {
            // Normalise to a bare ISO date (strip any time component).
            return substr($candidate, 0, 10);
        }

        return gmdate('Y-m-d');
    }

    /**
     * True when $date looks like a usable WHMCS date (not empty and not the
     * "0000-00-00" zero sentinel WHMCS uses for "no due date").
     */
    private static function isUsableDate(string $date): bool
    {
        if ($date === '') {
            return false;
        }
        if (strpos($date, '0000-00-00') === 0) {
            return false;
        }
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}/', $date);
    }

    /**
     * Resolve the API base URL.
     *
     * Precedence:
     *   1. Hostname from the WHMCS server config (if it looks like a real URL or host).
     *   2. self::DEFAULT_API_BASE_URL.
     */
    public static function resolveBaseUrl(array $params): string
    {
        // serverhostname is what WHMCS calls "Hostname" on the Server form.
        $host = $params['serverhostname'] ?? '';
        $host = is_string($host) ? trim($host) : '';

        if ($host === '') {
            // Fall back to the Reseller Console addon's configured base URL, then the default.
            $addonBase = trim((string) self::addonSetting('API Base URL', ''));
            return $addonBase !== '' ? rtrim($addonBase, '/') : self::DEFAULT_API_BASE_URL;
        }

        // Accept full URLs OR bare hostnames. Default scheme is https unless serversecure says otherwise.
        if (stripos($host, 'http://') === 0 || stripos($host, 'https://') === 0) {
            return rtrim($host, '/');
        }

        $secure = isset($params['serversecure']) ? (bool) $params['serversecure'] : true;
        $scheme = $secure ? 'https://' : 'http://';
        return $scheme . $host;
    }

    /**
     * Resolve the API key. WHMCS server Password field stores the sk_live_ key.
     */
    public static function resolveApiKey(array $params): string
    {
        // The sk_live_ key lives in the WHMCS server's Password field, which
        // WHMCS passes (decrypted) as `serverpassword`.
        $key = '';
        if (isset($params['serverpassword']) && is_string($params['serverpassword'])) {
            $key = trim($params['serverpassword']);
        }

        // IMPORTANT: do NOT fall back to $params['password']. In every service
        // lifecycle call (CreateAccount/ChangePackage/…), `password` is the
        // auto-generated *service* password (e.g. "9-X16]plgY1rJN"), NOT the API
        // key. Using it sent the service password as the bearer and produced a
        // confusing upstream `unauthorized: invalid_key` whenever the server
        // Password field was blank. The only valid sources are the server
        // Password field (above) and the addon "API Key" setting (below).

        // Fallback: the key set once in the Reseller Console addon module, so a
        // host can configure it in ONE place and leave the server Password blank.
        if ($key === '') {
            $addonKey = self::addonSetting('API Key', '');
            $key = is_string($addonKey) ? trim($addonKey) : '';
        }
        return $key;
    }

    /**
     * Construct a configured Api client from the WHMCS $params bag.
     *
     * Validates the resolved key up front and throws an actionable error rather
     * than letting a blank/wrong value reach the API as a cryptic
     * `unauthorized: invalid_key`.
     */
    public static function makeApiClient(array $params): Api
    {
        $apiKey = self::resolveApiKey($params);
        $baseUrl = self::resolveBaseUrl($params);

        if ($apiKey === '') {
            throw new SwarmzTransportException(
                'No Swarmz API key configured. Paste your sk_live_… key into the '
                . 'server\'s Password field (Setup → Products/Services → Servers), '
                . 'or set it in the Swarmz Reseller Console addon.'
            );
        }
        // A real key is `sk_live_…`. Anything else is almost certainly the wrong
        // field — most commonly the auto-generated service password landing here
        // because the server Password field was blank.
        if (strpos($apiKey, 'sk_') !== 0) {
            throw new SwarmzTransportException(
                'The configured Swarmz key does not look like an API key (expected '
                . 'sk_live_…). Check that the server\'s Password field holds your '
                . 'Swarmz API key and not a service or server password.'
            );
        }

        return new Api($apiKey, $baseUrl);
    }

    /**
     * Pull the selected plan_code (the "Plan" dropdown — the only product config
     * option) from the WHMCS $params bag.
     *
     * The dropdown's option keys are the plan `code`s, so the stored value IS
     * the plan_code. WHMCS exposes it both by name in configoptions['plan'] and
     * positionally (configoption1, as it is the only/first option). We read the
     * named form first (self-describing), then fall back to the positional
     * column.
     *
     * Returns '' when no plan is selected (the dropdown's "— Select a plan —"
     * sentinel), in which case the caller refuses to provision.
     *
     * @param array $params
     * @return string The selected plan code, or '' when none.
     */
    public static function getSelectedPlanCode(array $params): string
    {
        $opts = isset($params['configoptions']) && is_array($params['configoptions']) ? $params['configoptions'] : [];

        $raw = '';
        if (isset($opts['plan']) && is_string($opts['plan'])) {
            $raw = $opts['plan'];
        } elseif (isset($params['configoption1']) && is_string($params['configoption1'])) {
            $raw = $params['configoption1'];
        }

        $raw = trim($raw);
        // The "— Select a plan —" sentinel must never be treated as a real code.
        if ($raw === '' || $raw === self::PLAN_NONE_LABEL) {
            return '';
        }
        return $raw;
    }

    /**
     * Best-effort fetch of the account's named plans for UI population, tolerant
     * of an undeployed / unreachable platform-plans endpoint (mirrors the
     * platform-plan-refresh "endpoint maybe-undeployed" tolerance).
     *
     * Returns an empty array on ANY failure (no key, transport error, 404
     * because the endpoint isn't deployed yet, a non-2xx, …) so the caller can
     * render an empty dropdown with a note rather than throwing in the WHMCS
     * product-config screen.
     *
     * @param array $params The WHMCS $params bag (for key + base-url resolution).
     * @return array<int,array> The plans array (possibly empty).
     */
    public static function fetchPlansSafe(array $params): array
    {
        try {
            $api = self::makeApiClient($params);
            return $api->listPlans();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ---------------- Reseller Console (addon) settings ----------------
    //
    // The companion addon module (modules/addons/swarmz) stores host-side
    // settings in tbladdonmodules (module='swarmz', setting=<FriendlyName>).
    // These readers let the provisioning module honor those settings — the API
    // key/base-URL fallback above and the client-area presentation below —
    // while still working fine if the addon is absent (every reader defaults).

    /**
     * Read a saved Reseller Console setting. Returns $default when there is no
     * stored row (addon not installed/configured); otherwise returns the stored
     * value verbatim — which may be '' (important for unticked yes/no fields).
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function addonSetting(string $key, $default = null)
    {
        try {
            $row = Capsule::table('tbladdonmodules')
                ->where('module', self::ADDON_MODULE)
                ->where('setting', $key)
                ->first(['value']);
            if (!$row) {
                return $default;
            }
            return $row->value;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /** SSO button label shown to clients (host-brandable). */
    public static function editorButtonLabel(): string
    {
        $v = trim((string) self::addonSetting('Editor Button Label', 'Open AI Editor'));
        return $v !== '' ? $v : 'Open AI Editor';
    }

    /** What to call "credits" in the client area. */
    public static function creditTerm(): string
    {
        $v = trim((string) self::addonSetting('Credit Term', 'credits'));
        return $v !== '' ? $v : 'credits';
    }

    /** Whether to show AI USD spend to the client (default: yes). */
    public static function showAiSpend(): bool
    {
        return self::addonBool('Show AI Spend To Client', true);
    }

    /** Whether to show cloud USD spend to the client (default: yes). */
    public static function showCloudSpend(): bool
    {
        return self::addonBool('Show Cloud Spend To Client', true);
    }

    /** Optional host support URL shown in the client-area panel. */
    public static function supportUrl(): string
    {
        return trim((string) self::addonSetting('Support URL', ''));
    }

    /**
     * Interpret a stored yes/no addon setting robustly across WHMCS versions
     * (which may persist 'on', '1', '' or absent). No row → $default.
     */
    private static function addonBool(string $key, bool $default): bool
    {
        $raw = self::addonSetting($key, null);
        if ($raw === null) {
            return $default;
        }
        $v = strtolower(trim((string) $raw));
        return in_array($v, ['on', '1', 'yes', 'true', 'checked'], true);
    }

    /**
     * Build the WHU object passed to /platform-create.
     */
    public static function buildWhu(array $params): array
    {
        $client = isset($params['clientsdetails']) && is_array($params['clientsdetails']) ? $params['clientsdetails'] : [];

        $first = isset($client['firstname']) ? trim((string) $client['firstname']) : '';
        $last  = isset($client['lastname']) ? trim((string) $client['lastname']) : '';
        $name  = trim($first . ' ' . $last);
        if ($name === '') {
            $name = isset($client['companyname']) ? (string) $client['companyname'] : '';
        }
        if ($name === '') {
            $name = 'WHMCS Client #' . (string) ($params['userid'] ?? '0');
        }

        $email = isset($client['email']) ? (string) $client['email'] : '';

        return [
            'email' => $email,
            'name'  => $name,
        ];
    }

    /**
     * Read the swarmz tenant_id stored on the service custom field.
     * Returns null if not present.
     */
    public static function getTenantId($serviceId): ?string
    {
        return self::readServiceCustomField((int) $serviceId, self::CUSTOM_FIELD_TENANT_ID);
    }

    /**
     * Read the dashboard URL stored on the service custom field.
     */
    public static function getDashboardUrl($serviceId): ?string
    {
        return self::readServiceCustomField((int) $serviceId, self::CUSTOM_FIELD_DASHBOARD_URL);
    }

    /**
     * Save tenant_id + dashboard_url onto the service custom fields.
     * Auto-creates the custom-field rows on first use (linked to the product).
     */
    public static function setTenantId($serviceId, string $tenantId, string $dashboardUrl, ?int $productId = null): void
    {
        $serviceId = (int) $serviceId;

        if ($productId === null) {
            // Look it up from tblhosting.packageid
            try {
                $row = Capsule::table('tblhosting')->where('id', $serviceId)->first(['packageid']);
                $productId = $row ? (int) $row->packageid : null;
            } catch (\Throwable $e) {
                $productId = null;
            }
        }

        if ($productId !== null && $productId > 0) {
            self::ensureProductCustomField($productId, self::CUSTOM_FIELD_TENANT_ID);
            self::ensureProductCustomField($productId, self::CUSTOM_FIELD_DASHBOARD_URL);
        }

        self::writeServiceCustomField($serviceId, self::CUSTOM_FIELD_TENANT_ID, $tenantId);
        self::writeServiceCustomField($serviceId, self::CUSTOM_FIELD_DASHBOARD_URL, $dashboardUrl);
    }

    /**
     * Cache the latest usage snapshot onto the service (as JSON) so the client
     * area can render instantly + correctly even before its first live
     * UsageUpdate of the day. Written by the daily reconciliation cron.
     *
     * @param int                  $serviceId
     * @param array<string,float>  $usage  { credits, ai, cloud }
     */
    public static function cacheUsage(int $serviceId, array $usage): void
    {
        $serviceId = (int) $serviceId;
        if ($serviceId <= 0) {
            return;
        }
        $payload = json_encode([
            'credits'   => isset($usage['credits']) ? (float) $usage['credits'] : 0.0,
            'ai'        => isset($usage['ai']) ? (float) $usage['ai'] : 0.0,
            'cloud'     => isset($usage['cloud']) ? (float) $usage['cloud'] : 0.0,
            'cached_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        // Ensure the cache field exists on the service's product first.
        try {
            $row = Capsule::table('tblhosting')->where('id', $serviceId)->first(['packageid']);
            $productId = $row ? (int) $row->packageid : 0;
            if ($productId > 0) {
                self::ensureProductCustomField($productId, self::CUSTOM_FIELD_USAGE_CACHE);
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        self::writeServiceCustomField($serviceId, self::CUSTOM_FIELD_USAGE_CACHE, $payload);
    }

    /**
     * Read the cached usage snapshot for a service, or null if none/invalid.
     *
     * @param int $serviceId
     * @return array{credits:float,ai:float,cloud:float,cached_at:?string}|null
     */
    public static function getCachedUsage(int $serviceId): ?array
    {
        $raw = self::readServiceCustomField((int) $serviceId, self::CUSTOM_FIELD_USAGE_CACHE);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return [
            'credits'   => isset($decoded['credits']) ? (float) $decoded['credits'] : 0.0,
            'ai'        => isset($decoded['ai']) ? (float) $decoded['ai'] : 0.0,
            'cloud'     => isset($decoded['cloud']) ? (float) $decoded['cloud'] : 0.0,
            'cached_at' => isset($decoded['cached_at']) ? (string) $decoded['cached_at'] : null,
        ];
    }

    /**
     * Resolve the swarmz API key for a service from its server's Password field
     * (the per-service source of truth), without a full $params bag. Used by
     * the cron, which fires outside the provisioning lifecycle.
     *
     * Returns '' when the service has no dedicated server password (the caller
     * then falls back to the host-wide addon key).
     */
    public static function resolveServiceServerKey(int $serviceId): string
    {
        try {
            $row = Capsule::table('tblhosting as h')
                ->leftJoin('tblservers as s', 's.id', '=', 'h.server')
                ->where('h.id', (int) $serviceId)
                ->first(['s.password as serverpassword']);
            if (!$row || empty($row->serverpassword)) {
                return '';
            }
            // tblservers.password is stored encrypted; decrypt via WHMCS localAPI
            // when available. If we can't decrypt, return '' and let the caller
            // fall back to the addon key rather than sending ciphertext.
            $enc = (string) $row->serverpassword;
            if (function_exists('localAPI')) {
                try {
                    $res = localAPI('DecryptPassword', ['password2' => $enc]);
                    if (isset($res['password']) && is_string($res['password']) && trim($res['password']) !== '') {
                        return trim($res['password']);
                    }
                } catch (\Throwable $e) {
                    // fall through to ''
                }
            }
            return '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Ensure a "hidden" product custom field exists on this product.
     * Returns the field id.
     */
    public static function ensureProductCustomField(int $productId, string $fieldName): int
    {
        $existing = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->where('fieldname', $fieldName)
            ->first(['id']);

        if ($existing) {
            return (int) $existing->id;
        }

        $id = Capsule::table('tblcustomfields')->insertGetId([
            'type'         => 'product',
            'relid'        => $productId,
            'fieldname'    => $fieldName,
            'fieldtype'    => 'text',
            'description'  => 'Managed by the Swarmz module — do not edit.',
            'fieldoptions' => '',
            'regexpr'      => '',
            'adminonly'    => 'on',
            'required'     => '',
            'showorder'    => '',
            'showinvoice'  => '',
            'sortorder'    => '0',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        return (int) $id;
    }

    /**
     * Read a service's custom-field value by field name.
     */
    public static function readServiceCustomField(int $serviceId, string $fieldName): ?string
    {
        try {
            $row = Capsule::table('tblcustomfieldsvalues')
                ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
                ->where('tblcustomfields.type', 'product')
                ->where('tblcustomfields.fieldname', $fieldName)
                ->where('tblcustomfieldsvalues.relid', $serviceId)
                ->first(['tblcustomfieldsvalues.value']);
            if (!$row) {
                return null;
            }
            $value = (string) $row->value;
            return $value === '' ? null : $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Upsert a service's custom-field value (creates the value row if absent).
     * Field must already exist on the product (see ensureProductCustomField).
     */
    public static function writeServiceCustomField(int $serviceId, string $fieldName, string $value): void
    {
        try {
            // Resolve the field id by joining through the service's product.
            $field = Capsule::table('tblcustomfields')
                ->join('tblhosting', 'tblhosting.packageid', '=', 'tblcustomfields.relid')
                ->where('tblhosting.id', $serviceId)
                ->where('tblcustomfields.type', 'product')
                ->where('tblcustomfields.fieldname', $fieldName)
                ->first(['tblcustomfields.id as fieldid']);

            if (!$field) {
                return; // Caller should have ensureProductCustomField'd first.
            }

            $fieldId = (int) $field->fieldid;

            $existing = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)
                ->where('relid', $serviceId)
                ->first(['id']);

            if ($existing) {
                Capsule::table('tblcustomfieldsvalues')
                    ->where('id', $existing->id)
                    ->update([
                        'value'      => $value,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } else {
                Capsule::table('tblcustomfieldsvalues')->insert([
                    'fieldid'    => $fieldId,
                    'relid'      => $serviceId,
                    'value'      => $value,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            // Custom field plumbing is best-effort — the swarmz side is the source of truth
            // via the external_ref idempotency key.
        }
    }

    /**
     * Convert a human-readable swarmz API error into the short string WHMCS expects.
     * Strips any accidental key leakage as defence-in-depth.
     */
    public static function formatError(\Throwable $e, ?string $maskedKey = null): string
    {
        $msg = $e->getMessage();
        if ($maskedKey !== null && $maskedKey !== '') {
            $msg = str_replace($maskedKey, '[redacted]', $msg);
        }
        // Belt-and-braces: scrub anything resembling an sk_live_/sk_test_ token.
        $msg = preg_replace('/sk_(live|test)_[A-Za-z0-9_\\-]+/', '[redacted]', (string) $msg) ?? '';
        if ($e instanceof SwarmzApiException) {
            return $msg !== '' ? $msg : 'Swarmz API error.';
        }
        return 'Swarmz: ' . ($msg !== '' ? $msg : get_class($e));
    }

    /**
     * Apply the swarmz "0 / null = UNLIMITED" sentinel (decision D1) for the
     * capped entitlements (max_custom_domains, max_projects,
     * max_published_projects). Returns null for unlimited, or the positive cap.
     *
     * @param mixed $value
     * @return int|null  null = unlimited; positive int = hard cap
     */
    public static function unlimitedSentinel($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = filter_var($value, FILTER_VALIDATE_INT);
        if ($n === false || $n <= 0) {
            return null;
        }
        return (int) $n;
    }

}
