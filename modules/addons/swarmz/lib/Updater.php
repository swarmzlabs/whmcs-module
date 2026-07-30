<?php
/**
 * Swarmz Reseller Console — in-admin module updater.
 *
 * Shows when a newer module release is available and, on an explicit admin
 * click, installs it: download → verify → back up → overlay. Distribution is
 * the project's public GitHub Releases, the same ZIPs the install docs link.
 *
 * SECURITY MODEL — an updater is remote code installation by design, so every
 * step is fail-closed and none of these checks may ever be weakened:
 *
 *   1. PINNED SOURCE. Release metadata comes only from the GitHub API for the
 *      hard-coded repository (self::REPO) over TLS with peer verification.
 *      The download URL is taken from that API response, never from user
 *      input or any other channel.
 *   2. INTEGRITY. GitHub publishes a SHA-256 digest per release asset; the
 *      downloaded ZIP must match it exactly. No digest → no update.
 *   3. PATH ALLOWLIST. Every ZIP entry must live under
 *      modules/servers/swarmz/ or modules/addons/swarmz/ with no `..` and no
 *      absolute paths. One bad entry aborts the whole update before any file
 *      is touched.
 *   4. BACKUP FIRST. Both live module directories are copied to a
 *      dot-prefixed backup folder before anything is overwritten, and the
 *      result screen names it — rollback is "copy the backup back".
 *   5. ADDITIVE OVERLAY. Files are added or overwritten, never deleted —
 *      the same contract as a manual ZIP upload (see AGENTS.md).
 *   6. EXPLICIT + CSRF'd. Nothing updates automatically: the admin clicks
 *      the button, the POST carries the WHMCS admin token. There is no
 *      background/cron auto-update by design.
 *
 * Version checks are cached (tbladdonmodules) so the console does not hit
 * the GitHub API on every page load; a failed check degrades to "no banner",
 * never to an error in the host's admin.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

namespace WHMCS\Module\Addon\Swarmz;

use WHMCS\Database\Capsule;

class Updater
{
    /** The ONLY source of updates. Never make this configurable. */
    const REPO = 'swarmzlabs/whmcs-module';

    /** tbladdonmodules setting name for the cached version check. */
    const CACHE_SETTING = 'Update Check Cache';

    /** Re-check the API after this many seconds (6 h). */
    const CACHE_TTL_SECONDS = 21600;

    /** Hard cap on the release ZIP size (the real one is well under 1 MB). */
    const MAX_ZIP_BYTES = 26214400; // 25 MB

    const CONNECT_TIMEOUT = 10;
    const TIMEOUT = 90;

    /** The two directories a release may write into (relative to WHMCS root). */
    const ALLOWED_PREFIXES = ['modules/servers/swarmz/', 'modules/addons/swarmz/'];

    // ────────────────────────────────────────────────────────────── version

    /** The running module version (the server module's Api::VERSION). */
    public static function currentVersion(): string
    {
        if (class_exists('\\WHMCS\\Module\\Server\\Swarmz\\Api')) {
            return (string) \WHMCS\Module\Server\Swarmz\Api::VERSION;
        }
        return '0.0.0';
    }

    /**
     * Latest-release info, cached. Returns:
     *   ['ok' => true, 'version' => '1.14.0', 'zip_url' => …, 'sha256' => …,
     *    'notes' => …, 'checked_at' => ts]
     * or ['ok' => false, 'error' => …]. A transport failure while a valid
     * cache exists returns the cache (stale beats broken).
     */
    public static function check(bool $force = false): array
    {
        $cached = self::readCache();
        if (!$force && $cached !== null && (time() - (int) ($cached['checked_at'] ?? 0)) < self::CACHE_TTL_SECONDS) {
            return $cached;
        }

        $fresh = self::fetchLatestRelease();
        if ($fresh['ok']) {
            self::writeCache($fresh);
            return $fresh;
        }
        // Degrade to the stale cache when we have one; else surface the error.
        return $cached !== null ? $cached : $fresh;
    }

    /** True when the (cached) latest release is newer than the running code. */
    public static function updateAvailable(?array $info = null): bool
    {
        $info = $info ?? self::check();
        if (empty($info['ok']) || empty($info['version'])) {
            return false;
        }
        return version_compare((string) $info['version'], self::currentVersion(), '>');
    }

    // ──────────────────────────────────────────────────────────── preflight

    /**
     * Environment checks, each ['label' => …, 'ok' => bool, 'detail' => …].
     * All must pass before performUpdate() will touch anything.
     */
    public static function preflight(): array
    {
        $checks = [];
        $checks[] = [
            'label' => 'PHP zip extension',
            'ok' => class_exists('\\ZipArchive'),
            'detail' => class_exists('\\ZipArchive') ? 'ZipArchive available' : 'Install/enable the PHP zip extension',
        ];
        $checks[] = [
            'label' => 'PHP curl extension',
            'ok' => function_exists('curl_init'),
            'detail' => function_exists('curl_init') ? 'curl available' : 'Install/enable the PHP curl extension',
        ];
        $root = self::whmcsRoot();
        $checks[] = [
            'label' => 'WHMCS root resolved',
            'ok' => $root !== '',
            'detail' => $root !== '' ? $root : 'Could not resolve the WHMCS root directory',
        ];
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            $dir = $root . '/' . rtrim($prefix, '/');
            $writable = $root !== '' && is_dir($dir) && is_writable($dir);
            $checks[] = [
                'label' => 'Writable: ' . $prefix,
                'ok' => $writable,
                'detail' => $writable
                    ? 'OK'
                    : 'The web server user cannot write here — update by uploading the release ZIP instead',
            ];
        }
        $modulesDir = $root . '/modules';
        $checks[] = [
            'label' => 'Writable: modules/ (for the backup folder)',
            'ok' => $root !== '' && is_writable($modulesDir),
            'detail' => ($root !== '' && is_writable($modulesDir)) ? 'OK' : 'Backups need write access to modules/',
        ];
        return $checks;
    }

    // ─────────────────────────────────────────────────────────────── update

    /**
     * Download, verify, back up, and install the latest release. Returns
     *   ['ok' => true, 'from' => …, 'to' => …, 'files' => n, 'backup' => path]
     * or ['ok' => false, 'error' => …]. Fails closed: nothing on disk is
     * touched until the archive is fully downloaded, digest-verified, and
     * path-validated; the backup exists before the first overwrite.
     */
    public static function performUpdate(): array
    {
        $from = self::currentVersion();
        $info = self::check(true);
        if (empty($info['ok'])) {
            return ['ok' => false, 'error' => 'Could not reach the release feed: ' . ($info['error'] ?? 'unknown')];
        }
        if (!self::updateAvailable($info)) {
            return ['ok' => false, 'error' => 'Already up to date (v' . $from . ').'];
        }
        if (empty($info['zip_url']) || empty($info['sha256'])) {
            return ['ok' => false, 'error' => 'The release is missing a ZIP asset or its SHA-256 digest; not proceeding.'];
        }
        foreach (self::preflight() as $c) {
            if (!$c['ok']) {
                return ['ok' => false, 'error' => 'Preflight failed — ' . $c['label'] . ': ' . $c['detail']];
            }
        }
        $root = self::whmcsRoot();

        // 1. Download to a temp file.
        $tmpZip = tempnam(sys_get_temp_dir(), 'swz-upd-');
        if ($tmpZip === false) {
            return ['ok' => false, 'error' => 'Could not create a temp file.'];
        }
        $dl = self::download((string) $info['zip_url'], $tmpZip);
        if ($dl !== true) {
            @unlink($tmpZip);
            return ['ok' => false, 'error' => 'Download failed: ' . $dl];
        }

        // 2. Verify the SHA-256 digest GitHub published for the asset.
        $gotHash = hash_file('sha256', $tmpZip);
        if (!is_string($gotHash) || !hash_equals(strtolower((string) $info['sha256']), strtolower($gotHash))) {
            @unlink($tmpZip);
            self::log('Updater.DigestMismatch', ['expected' => $info['sha256']], ['got' => $gotHash]);
            return ['ok' => false, 'error' => 'Checksum mismatch — the downloaded file does not match the published release. Aborted; nothing was changed.'];
        }

        // 3. Validate EVERY archive path before touching anything.
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            return ['ok' => false, 'error' => 'Could not open the downloaded archive.'];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!self::entryAllowed($name)) {
                $zip->close();
                @unlink($tmpZip);
                self::log('Updater.BadEntry', [], ['entry' => $name]);
                return ['ok' => false, 'error' => 'Unexpected path in archive (' . $name . '). Aborted; nothing was changed.'];
            }
        }

        // 4. Back up both live module directories.
        $backupDir = $root . '/modules/.swarmz-backup-' . preg_replace('/[^0-9a-zA-Z.]/', '', $from) . '-' . date('Ymd-His');
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            $live = $root . '/' . rtrim($prefix, '/');
            $dest = $backupDir . '/' . rtrim($prefix, '/');
            if (is_dir($live) && !self::copyTree($live, $dest)) {
                $zip->close();
                @unlink($tmpZip);
                return ['ok' => false, 'error' => 'Could not create the backup at ' . $backupDir . '. Aborted; nothing was changed.'];
            }
        }

        // 5. Extract to temp, then overlay (add/overwrite only, never delete).
        $stage = sys_get_temp_dir() . '/swz-upd-stage-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0755, true) || !$zip->extractTo($stage)) {
            $zip->close();
            @unlink($tmpZip);
            return ['ok' => false, 'error' => 'Could not extract the archive. Nothing was changed (backup at ' . $backupDir . ').'];
        }
        $zip->close();
        @unlink($tmpZip);

        $copied = 0;
        $failed = [];
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            $src = $stage . '/' . rtrim($prefix, '/');
            if (!is_dir($src)) {
                continue;
            }
            self::overlayTree($src, $root . '/' . rtrim($prefix, '/'), $copied, $failed);
        }
        self::removeTree($stage);

        if (!empty($failed)) {
            self::log('Updater.PartialFailure', [], ['failed' => array_slice($failed, 0, 20), 'backup' => $backupDir]);
            return [
                'ok' => false,
                'error' => count($failed) . ' file(s) could not be written (first: ' . $failed[0] . '). '
                    . 'Restore by copying back the backup at ' . $backupDir . ', or re-upload the release ZIP.',
            ];
        }

        self::writeCache(['ok' => true] + $info + ['checked_at' => 0]); // bust TTL so the banner clears next load
        self::log('Updater.Updated', ['from' => $from, 'to' => $info['version']], ['files' => $copied, 'backup' => $backupDir]);

        return [
            'ok' => true,
            'from' => $from,
            'to' => (string) $info['version'],
            'files' => $copied,
            'backup' => $backupDir,
        ];
    }

    // ──────────────────────────────────────────────────────────── internals

    /** GitHub latest-release metadata → normalized info array. */
    private static function fetchLatestRelease(): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl unavailable'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github+json',
                'User-Agent: swarmz-whmcs/' . self::currentVersion(),
            ],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code !== 200) {
            return ['ok' => false, 'error' => $err !== '' ? $err : ('HTTP ' . $code)];
        }
        $rel = json_decode($raw, true);
        if (!is_array($rel) || empty($rel['tag_name'])) {
            return ['ok' => false, 'error' => 'unexpected release feed shape'];
        }
        $version = ltrim((string) $rel['tag_name'], 'vV');
        $zipUrl = '';
        $sha256 = '';
        foreach ((array) ($rel['assets'] ?? []) as $asset) {
            if (!is_array($asset) || substr((string) ($asset['name'] ?? ''), -4) !== '.zip') {
                continue;
            }
            if ((int) ($asset['size'] ?? 0) > self::MAX_ZIP_BYTES) {
                continue;
            }
            $zipUrl = (string) ($asset['browser_download_url'] ?? '');
            $digest = (string) ($asset['digest'] ?? '');
            if (strpos($digest, 'sha256:') === 0) {
                $sha256 = substr($digest, 7);
            }
            break;
        }
        $notes = trim((string) ($rel['body'] ?? ''));
        if (function_exists('mb_substr')) {
            $notes = mb_substr($notes, 0, 4000);
        } else {
            $notes = substr($notes, 0, 4000);
        }
        return [
            'ok'         => true,
            'version'    => $version,
            'zip_url'    => $zipUrl,
            'sha256'     => $sha256,
            'notes'      => $notes,
            'checked_at' => time(),
        ];
    }

    /** Download $url to $destPath. true on success, else an error string. */
    private static function download(string $url, string $destPath)
    {
        // Belt and braces: the URL came from the pinned repo's API response,
        // but insist on HTTPS + a GitHub host anyway.
        $host = (string) parse_url($url, PHP_URL_HOST);
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' || ($host !== 'github.com' && substr($host, -15) !== '.githubusercontent.com')) {
            return 'unexpected download host';
        }
        $fh = fopen($destPath, 'wb');
        if ($fh === false) {
            return 'cannot open temp file';
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,   // github.com 302s to *.githubusercontent.com
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE    => self::MAX_ZIP_BYTES,
            CURLOPT_HTTPHEADER     => ['User-Agent: swarmz-whmcs/' . self::currentVersion()],
        ]);
        $okDl = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);
        if ($okDl !== true || $code !== 200) {
            return $err !== '' ? $err : ('HTTP ' . $code);
        }
        if ((int) filesize($destPath) > self::MAX_ZIP_BYTES) {
            return 'archive exceeds the size cap';
        }
        return true;
    }

    /** A ZIP entry is acceptable only inside the two module dirs, no tricks. */
    private static function entryAllowed(string $name): bool
    {
        if ($name === '' || $name[0] === '/' || strpos($name, '\\') !== false) {
            return false;
        }
        if (strpos($name, '..') !== false || strpos($name, "\0") !== false) {
            return false;
        }
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (strpos($name, $prefix) === 0 || rtrim($name, '/') . '/' === $prefix
                || strpos($prefix, rtrim($name, '/') . '/') === 0) {
                // The entry is inside an allowed dir, or IS one of the parent
                // directory entries (modules/, modules/servers/, …).
                return true;
            }
        }
        return false;
    }

    /** Recursive copy (backup). False on first failure. */
    private static function copyTree(string $src, string $dst): bool
    {
        if (!is_dir($dst) && !mkdir($dst, 0755, true)) {
            return false;
        }
        $items = scandir($src);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            if (is_link($s)) {
                continue; // never follow links
            }
            if (is_dir($s)) {
                if (!self::copyTree($s, $d)) {
                    return false;
                }
            } elseif (!copy($s, $d)) {
                return false;
            }
        }
        return true;
    }

    /** Recursive overlay (install): add/overwrite, never delete. */
    private static function overlayTree(string $src, string $dst, int &$copied, array &$failed): void
    {
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        $items = scandir($src);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            if (is_link($s)) {
                continue;
            }
            if (is_dir($s)) {
                self::overlayTree($s, $d, $copied, $failed);
            } elseif (copy($s, $d)) {
                $copied++;
            } else {
                $failed[] = str_replace(self::whmcsRoot() . '/', '', $d);
            }
        }
    }

    /** Remove a temp tree (staging only — never called on live dirs). */
    private static function removeTree(string $dir): void
    {
        if (strpos($dir, sys_get_temp_dir()) !== 0) {
            return; // hard guard: only ever delete inside the system temp dir
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) {
                self::removeTree($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private static function whmcsRoot(): string
    {
        if (defined('ROOTDIR') && is_dir(ROOTDIR)) {
            return rtrim((string) ROOTDIR, '/');
        }
        // modules/addons/swarmz/lib/Updater.php → four levels up.
        $guess = dirname(__DIR__, 4);
        return is_dir($guess . '/modules') ? $guess : '';
    }

    private static function readCache(): ?array
    {
        try {
            $row = Capsule::table('tbladdonmodules')
                ->where('module', 'swarmz')->where('setting', self::CACHE_SETTING)
                ->first(['value']);
            if (!$row) {
                return null;
            }
            $data = json_decode((string) $row->value, true);
            return (is_array($data) && !empty($data['ok'])) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function writeCache(array $info): void
    {
        try {
            $value = json_encode($info);
            $updated = Capsule::table('tbladdonmodules')
                ->where('module', 'swarmz')->where('setting', self::CACHE_SETTING)
                ->update(['value' => $value]);
            if ($updated === 0) {
                Capsule::table('tbladdonmodules')->insert([
                    'module' => 'swarmz',
                    'setting' => self::CACHE_SETTING,
                    'value' => $value,
                ]);
            }
        } catch (\Throwable $e) {
            // Cache is best-effort; the check just re-runs next time.
        }
    }

    private static function log(string $action, $request, $response): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        try {
            logModuleCall('swarmz', $action, $request, $response, $response, ['sk_live_', 'sk_test_']);
        } catch (\Throwable $e) {
        }
    }
}
