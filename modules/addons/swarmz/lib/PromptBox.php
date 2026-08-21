<?php
/**
 * Swarmz WHMCS Module — Prompt Box (host-storefront prompt capture).
 *
 * The host embeds a small widget (served by promptbox.php?a=js) on ANY page —
 * plain HTML, WordPress, a landing builder. A visitor types the app they want,
 * the widget stores the prompt here as a short-lived "intent" and sends the
 * visitor into the WHMCS cart carrying only an opaque token (`?swzp=…`).
 * Checkout hooks (hooks.php) bind the token to the resulting service, and the
 * provisioning module attaches the prompt to platform-create as
 * `initial_prompt` — so the customer's very first login drops them into the
 * editor watching the app they asked for being built.
 *
 * All storage is one table (mod_swarmz_prompt_intents), created on addon
 * activation/upgrade and lazily on first use as a belt-and-braces.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Addon\Swarmz;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

class PromptBox
{
    /** Intent storage table (WHMCS convention: mod_<module>_…). */
    const TABLE = 'mod_swarmz_prompt_intents';

    /**
     * Express-signup attempt log — one row per a=express request that reaches
     * the throttle, written regardless of the request's outcome. The intents
     * table can't double as the rate counter for express because an intent row
     * is only written on a *successful* signup (after AddClient), so early
     * exits (a duplicate-email 409 in particular) would never count and the
     * per-IP ceiling could be bypassed by looping a known email.
     */
    const ATTEMPTS_TABLE = 'mod_swarmz_express_attempts';

    /** Cart query parameter carrying the intent token. */
    const CART_PARAM = 'swzp';

    /** PHP session key holding the visitor's intent token through checkout. */
    const SESSION_KEY = 'swarmz_prompt_token';

    /** Hard cap on stored prompt length (matches the platform API's cap). */
    const PROMPT_MAX_CHARS = 10000;

    /** Per-IP intent creations tolerated per hour (widget endpoint is public). */
    const RATE_LIMIT_PER_HOUR = 30;

    /**
     * Per-IP ceiling for the express-signup endpoint (promptbox.php?a=express).
     * Lower than RATE_LIMIT_PER_HOUR: express signup also places a $0 order
     * and creates a WHMCS client per attempt, so it warrants a tighter throttle
     * than the plain "store a prompt" intent endpoint.
     */
    const EXPRESS_RATE_LIMIT_PER_HOUR = 10;

    /** Intents older than this many days are purged by the daily cron. */
    const RETENTION_DAYS = 30;

    /**
     * Create the module's tables if missing. Idempotent and additive: each
     * table is guarded by its own hasTable() so a later-added table (the
     * express attempts log) is created on installs that already have the
     * intents table. Called from activate/upgrade and lazily before any
     * read/write. Never drops or alters an existing table.
     */
    public static function ensureSchema(): void
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, function ($table) {
                    $table->increments('id');
                    $table->string('token', 64)->unique();
                    $table->text('prompt');
                    $table->unsignedInteger('pid')->default(0);
                    $table->unsignedInteger('service_id')->nullable()->index();
                    $table->unsignedInteger('order_id')->nullable();
                    $table->string('ip', 45)->default('');
                    $table->dateTime('created_at');
                    $table->dateTime('bound_at')->nullable();
                    $table->dateTime('used_at')->nullable();
                });
            }
            if (!$schema->hasTable(self::ATTEMPTS_TABLE)) {
                $schema->create(self::ATTEMPTS_TABLE, function ($table) {
                    $table->increments('id');
                    $table->string('ip', 45)->default('');
                    $table->dateTime('created_at');
                    $table->string('step', 40)->nullable();
                    $table->text('note')->nullable();
                    $table->index(['ip', 'created_at']);
                });
            } else {
                // Additive upgrade path (v1.22.0): installs that already ran
                // ensureSchema() on an earlier version have this table
                // WITHOUT the two diagnostics columns below. Add each one
                // individually, guarded by its own hasColumn(), so a
                // partially-upgraded table (one column already present, e.g.
                // from an interrupted request) is still handled correctly
                // and nothing here ever touches existing rows.
                if (!$schema->hasColumn(self::ATTEMPTS_TABLE, 'step')) {
                    $schema->table(self::ATTEMPTS_TABLE, function ($table) {
                        $table->string('step', 40)->nullable();
                    });
                }
                if (!$schema->hasColumn(self::ATTEMPTS_TABLE, 'note')) {
                    $schema->table(self::ATTEMPTS_TABLE, function ($table) {
                        $table->text('note')->nullable();
                    });
                }
            }
        } catch (\Throwable $e) {
            // Schema plumbing is best-effort — a failure surfaces on first use.
        }
    }

    /**
     * Record one express-signup attempt for this IP (outcome-independent), so
     * countExpressAttempts() throttles by requests reaching the endpoint rather
     * than by successful signups. Best-effort — a logging failure must never
     * block or fail the signup it is meant to rate-limit.
     *
     * Returns the inserted row id (0 on failure), so the caller can later
     * attach this request's outcome via finishExpressAttempt(). That pairing
     * is the only diagnostics this flow has: logModuleCall never fires from
     * promptbox.php's public, unauthenticated context.
     */
    public static function recordExpressAttempt(string $ip): int
    {
        try {
            self::ensureSchema();
            return (int) Capsule::table(self::ATTEMPTS_TABLE)->insertGetId([
                'ip'         => substr($ip, 0, 45),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Attach the flow's final step + a non-identifying note to an attempt
     * row created by recordExpressAttempt(), so the Reseller Console's
     * "Recent express signups" panel can show WHY a signup ended the way it
     * did. Best-effort and never throws; $id <= 0 (recordExpressAttempt()
     * itself failed, or the caller never recorded an attempt) is a silent
     * no-op — there is no row to update.
     *
     * $note is written verbatim to mod_swarmz_express_attempts and rendered
     * in the console: callers MUST NEVER pass the visitor's password here.
     * An email prefix (never the full address) is fine.
     */
    public static function finishExpressAttempt(int $id, string $step, string $note = ''): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            self::ensureSchema();
            Capsule::table(self::ATTEMPTS_TABLE)->where('id', $id)->update([
                'step' => substr($step, 0, 40),
                'note' => substr($note, 0, 255),
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /**
     * Latest express-signup attempts for the Reseller Console's "Recent
     * express signups" diagnostics panel — mirrors recentIntents() below.
     * Outcome-independent and capped the same way: newest first, capped at
     * 100 regardless of what the caller asks for.
     *
     * @return array<int,\stdClass>
     */
    public static function recentExpressAttempts(int $limit = 20): array
    {
        try {
            self::ensureSchema();
            $rows = Capsule::table(self::ATTEMPTS_TABLE)
                ->orderBy('id', 'desc')
                ->limit(max(1, min(100, $limit)))
                ->get(['id', 'created_at', 'step', 'note']);
            $out = [];
            foreach ($rows as $r) {
                $out[] = $r;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Express-signup attempts from $ip within the trailing $sinceSeconds. */
    public static function countExpressAttempts(string $ip, int $sinceSeconds): int
    {
        self::ensureSchema();
        $since = date('Y-m-d H:i:s', time() - $sinceSeconds);
        return (int) Capsule::table(self::ATTEMPTS_TABLE)
            ->where('ip', $ip)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /** True when the intents table exists (addon activated at least once). */
    public static function schemaReady(): bool
    {
        try {
            return Capsule::schema()->hasTable(self::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Store a new prompt intent. Returns [ok, tokenOrError].
     *
     * @param string $prompt Visitor-typed prompt (trimmed + capped here).
     * @param int    $pid    WHMCS product id the widget was configured with.
     * @param string $ip     Requesting IP (rate limiting only).
     * @return array{0:bool, 1:string} [true, token] or [false, error_code]
     */
    public static function createIntent(string $prompt, int $pid, string $ip): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            return [false, 'empty_prompt'];
        }
        if (function_exists('mb_substr')) {
            $prompt = mb_substr($prompt, 0, self::PROMPT_MAX_CHARS);
        } else {
            $prompt = substr($prompt, 0, self::PROMPT_MAX_CHARS);
        }
        if ($pid <= 0 || !self::isSwarmzProduct($pid)) {
            return [false, 'unknown_product'];
        }

        self::ensureSchema();

        // Public endpoint → keep a runaway (or hostile) page from filling the
        // table. Per-IP, sliding hour.
        try {
            $recent = self::countRecentFromIp($ip, 3600);
            if ($recent >= self::RATE_LIMIT_PER_HOUR) {
                return [false, 'rate_limited'];
            }
        } catch (\Throwable $e) {
            return [false, 'storage_unavailable'];
        }

        $token = bin2hex(random_bytes(16));
        try {
            Capsule::table(self::TABLE)->insert([
                'token'      => $token,
                'prompt'     => $prompt,
                'pid'        => $pid,
                'ip'         => substr($ip, 0, 45),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return [false, 'storage_unavailable'];
        }

        return [true, $token];
    }

    /**
     * Count intents created from an IP within the last N seconds. Shared by
     * the standard per-IP throttle above and ExpressSignup's stricter
     * ceiling (EXPRESS_RATE_LIMIT_PER_HOUR) — one counting query, two
     * callers, so the throttle logic can't drift between them.
     *
     * Throws on a storage failure (schema missing, DB unreachable); callers
     * decide how to surface that (createIntent() maps it to
     * 'storage_unavailable', distinct from actually being over the ceiling).
     */
    public static function countRecentFromIp(string $ip, int $sinceSeconds): int
    {
        self::ensureSchema();
        $since = date('Y-m-d H:i:s', time() - $sinceSeconds);
        return (int) Capsule::table(self::TABLE)
            ->where('ip', $ip)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * The cart URL the widget redirects to after storing an intent.
     */
    public static function cartUrl(int $pid, string $token): string
    {
        return rtrim(self::systemUrl(), '/')
            . '/cart.php?a=add&pid=' . $pid . '&' . self::CART_PARAM . '=' . rawurlencode($token);
    }

    /**
     * Bind an intent token to a provisioned/ordered WHMCS service. Idempotent:
     * re-binding the same token to the same service is a no-op; a token already
     * bound to a DIFFERENT service is left alone (first bind wins).
     */
    public static function bindToService(string $token, int $serviceId, ?int $orderId = null): bool
    {
        if ($token === '' || $serviceId <= 0 || !self::schemaReady()) {
            return false;
        }
        try {
            $row = Capsule::table(self::TABLE)->where('token', $token)->first(['id', 'service_id']);
            if (!$row) {
                return false;
            }
            if (!empty($row->service_id)) {
                return (int) $row->service_id === $serviceId;
            }
            Capsule::table(self::TABLE)->where('id', $row->id)->whereNull('service_id')->update([
                'service_id' => $serviceId,
                'order_id'   => $orderId,
                'bound_at'   => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The unused prompt bound to a service, or null. Read by the provisioning
     * module's CreateAccount to attach `initial_prompt` to platform-create.
     */
    public static function pendingPromptForService(int $serviceId): ?string
    {
        if ($serviceId <= 0 || !self::schemaReady()) {
            return null;
        }
        try {
            $row = Capsule::table(self::TABLE)
                ->where('service_id', $serviceId)
                ->whereNull('used_at')
                ->orderBy('id', 'desc')
                ->first(['prompt']);
            if (!$row || !is_string($row->prompt) || trim($row->prompt) === '') {
                return null;
            }
            return $row->prompt;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Mark a service's bound intent consumed (provisioned with the prompt). */
    public static function markUsedForService(int $serviceId): void
    {
        if ($serviceId <= 0 || !self::schemaReady()) {
            return;
        }
        try {
            Capsule::table(self::TABLE)
                ->where('service_id', $serviceId)
                ->whereNull('used_at')
                ->update(['used_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /** Purge intents past retention. Called from the addon's daily-cron hook. */
    public static function purgeStale(): int
    {
        if (!self::schemaReady()) {
            return 0;
        }
        try {
            $cutoff = date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400);
            $purged = (int) Capsule::table(self::TABLE)->where('created_at', '<', $cutoff)->delete();
            // Attempt-log rows only matter for the trailing hour; a day's
            // retention is ample and keeps the table from growing unbounded.
            if (Capsule::schema()->hasTable(self::ATTEMPTS_TABLE)) {
                $attemptCutoff = date('Y-m-d H:i:s', time() - 86400);
                Capsule::table(self::ATTEMPTS_TABLE)->where('created_at', '<', $attemptCutoff)->delete();
            }
            return $purged;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Latest intents for the Reseller Console's Prompt Box view.
     *
     * @return array<int,\stdClass>
     */
    public static function recentIntents(int $limit = 20): array
    {
        if (!self::schemaReady()) {
            return [];
        }
        try {
            $rows = Capsule::table(self::TABLE)
                ->orderBy('id', 'desc')
                ->limit(max(1, min(100, $limit)))
                ->get(['id', 'token', 'prompt', 'pid', 'service_id', 'created_at', 'bound_at', 'used_at']);
            $out = [];
            foreach ($rows as $r) {
                $out[] = $r;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Products backed by the swarmz provisioning module (candidates for the
     * widget's data-pid). name + pid + monthly price for the snippet builder.
     *
     * @return array<int,array{pid:int,name:string}>
     */
    public static function swarmzProducts(): array
    {
        try {
            $rows = Capsule::table('tblproducts')
                ->where('servertype', 'swarmz')
                ->orderBy('order')
                ->get(['id', 'name', 'hidden']);
            $out = [];
            foreach ($rows as $r) {
                $out[] = ['pid' => (int) $r->id, 'name' => (string) $r->name, 'hidden' => !empty($r->hidden)];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** True when the pid is a swarmz-module product (widget misuse guard). */
    public static function isSwarmzProduct(int $pid): bool
    {
        try {
            return Capsule::table('tblproducts')
                ->where('id', $pid)
                ->where('servertype', 'swarmz')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** WHMCS SystemURL (no trailing slash guarantees — callers rtrim). */
    public static function systemUrl(): string
    {
        try {
            $row = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->first(['value']);
            if ($row && is_string($row->value) && trim($row->value) !== '') {
                return trim($row->value);
            }
        } catch (\Throwable $e) {
            // fall through
        }
        // Last resort: derive from the current request.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
        return $scheme . '://' . $host;
    }
}
