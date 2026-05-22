<?php
/**
 * Swarmz WHMCS Module - Helpers
 *
 * Pure-function helpers: config-options → entitlements mapping, external_ref
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

    /** Allowed compute-size values for max_compute_size. */
    const ALLOWED_COMPUTE_SIZES = [
        'nano', 'micro', 'small', 'medium', 'large', 'xlarge', '2xl', '4xl',
    ];

    /** Default base URL used if the WHMCS server hostname is blank. */
    const DEFAULT_API_BASE_URL = 'https://api.swarmz.net';

    /**
     * Build the WHMCS-side idempotency key passed to swarmz as `external_ref`.
     * Format: "whmcs:<serviceid>". A retried provision uses the same key.
     */
    public static function buildExternalRef($serviceId): string
    {
        return 'whmcs:' . (string) $serviceId;
    }

    /**
     * Translate WHMCS `$params['configoption1..7']` into the swarmz entitlements{} JSON.
     *
     * Per §10 of reseller-functions-rewrite.md:
     *   1 credits_per_day          ""  → null (unlimited)
     *   2 monthly_credit_cap       ""  → null (no ceiling)
     *   3 max_projects             ""  → null (unlimited)
     *   4 max_custom_domains       ""  → 0
     *   5 max_compute_size         dropdown ("nano".."4xl"), default "nano"
     *   6 cloud_budget_cap         ""  → null (no ceiling, USD)
     *   7 default_credits_topup    ""  → 0   (consumed at create-time only)
     *
     * `default_credits_topup` is NOT an entitlement; it stays in $params and is
     * applied by CreateAccount via /enterprise-topup. We strip it from the
     * returned array.
     *
     * @return array<string,mixed>
     */
    public static function mapConfigOptionsToEntitlements(array $params): array
    {
        $get = function ($keys) use ($params) {
            foreach ($keys as $k) {
                if (isset($params[$k]) && $params[$k] !== '' && $params[$k] !== null) {
                    return $params[$k];
                }
            }
            return null;
        };

        // configoptionN is the legacy numeric form; named keys live in configoptions[].
        $opts = isset($params['configoptions']) && is_array($params['configoptions']) ? $params['configoptions'] : [];

        $creditsPerDay      = $get(['configoption1']) ?? ($opts['credits_per_day']         ?? null);
        $monthlyCreditCap   = $get(['configoption2']) ?? ($opts['monthly_credit_cap']      ?? null);
        $maxProjects        = $get(['configoption3']) ?? ($opts['max_projects']            ?? null);
        $maxCustomDomains   = $get(['configoption4']) ?? ($opts['max_custom_domains']      ?? null);
        $maxComputeSize     = $get(['configoption5']) ?? ($opts['max_compute_size']        ?? null);
        $cloudBudgetCap     = $get(['configoption6']) ?? ($opts['cloud_budget_cap']        ?? null);
        // default_credits_topup is configoption7; resolved separately.

        $entitlements = [
            'credits_per_day'     => self::parseIntOrNull($creditsPerDay),
            'monthly_credit_cap'  => self::parseIntOrNull($monthlyCreditCap),
            'max_projects'        => self::parseIntOrNull($maxProjects),
            'max_custom_domains'  => self::parseIntOrZero($maxCustomDomains),
            'max_compute_size'    => self::parseComputeSize($maxComputeSize),
            'cloud_budget_cap'    => self::parseNumericOrNull($cloudBudgetCap),
        ];

        return $entitlements;
    }

    /**
     * Pull configoption7 (default_credits_topup) as a non-negative integer.
     */
    public static function getDefaultCreditsTopup(array $params): int
    {
        $value = $params['configoption7'] ?? ($params['configoptions']['default_credits_topup'] ?? '0');
        $n = self::parseIntOrZero($value);
        return $n > 0 ? $n : 0;
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
            return self::DEFAULT_API_BASE_URL;
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
        // serverpassword is the decrypted server password (provided by WHMCS).
        $key = '';
        if (isset($params['serverpassword']) && is_string($params['serverpassword'])) {
            $key = trim($params['serverpassword']);
        }
        // Some WHMCS contexts expose 'password' top-level mirror of the server password.
        if ($key === '' && isset($params['password']) && is_string($params['password'])) {
            $key = trim($params['password']);
        }
        return $key;
    }

    /**
     * Construct a configured Api client from the WHMCS $params bag.
     */
    public static function makeApiClient(array $params): Api
    {
        $apiKey = self::resolveApiKey($params);
        $baseUrl = self::resolveBaseUrl($params);
        return new Api($apiKey, $baseUrl);
    }

    /**
     * Build the WHU object passed to /enterprise-create.
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

    // ---------------- internal parsers ----------------

    private static function parseIntOrNull($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        $n = filter_var($value, FILTER_VALIDATE_INT);
        if ($n === false) {
            return null;
        }
        return $n < 0 ? null : (int) $n;
    }

    private static function parseIntOrZero($value): int
    {
        $n = self::parseIntOrNull($value);
        return $n === null ? 0 : $n;
    }

    private static function parseNumericOrNull($value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        return $f < 0 ? null : $f;
    }

    private static function parseComputeSize($value): string
    {
        if (!is_string($value)) {
            return 'nano';
        }
        $v = strtolower(trim($value));
        return in_array($v, self::ALLOWED_COMPUTE_SIZES, true) ? $v : 'nano';
    }
}
