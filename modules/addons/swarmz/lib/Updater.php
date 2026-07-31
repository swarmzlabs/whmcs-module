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

    /** Per-release file manifest, shipped inside every release (>= 1.15.0). */
    const MANIFEST_REL = 'modules/addons/swarmz/release-manifest.json';

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

    // ─────────────────────────────────────────────── local modifications

    /**
     * The manifest shipped with the INSTALLED version, or null when the
     * install predates manifests (< 1.15.0) or the file is unreadable.
     */
    public static function installedManifest(): ?array
    {
        $root = self::whmcsRoot();
        if ($root === '') {
            return null;
        }
        $path = $root . '/' . self::MANIFEST_REL;
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || empty($data['files']) || !is_array($data['files'])) {
            return null;
        }
        return $data;
    }

    /**
     * Compare the live module files against the installed release manifest.
     *
     *   ['manifest' => bool,          // false = no manifest (pre-1.15 install)
     *    'modified' => [rel paths],   // content differs from what shipped
     *    'missing'  => [rel paths]]   // shipped file deleted locally
     *
     * Any entry in modified/missing means a human changed this install by
     * hand; performUpdate() refuses to overwrite those without the admin's
     * explicit confirmation — see the update page.
     */
    public static function detectLocalModifications(): array
    {
        $out = ['manifest' => false, 'modified' => [], 'missing' => []];
        $manifest = self::installedManifest();
        if ($manifest === null) {
            return $out;
        }
        $out['manifest'] = true;
        $root = self::whmcsRoot();
        foreach ($manifest['files'] as $rel => $hash) {
            $rel = (string) $rel;
            if (!is_string($hash) || !self::entryAllowed($rel) || $rel === self::MANIFEST_REL) {
                continue;
            }
            $path = $root . '/' . $rel;
            if (!is_file($path)) {
                $out['missing'][] = $rel;
                continue;
            }
            $live = hash_file('sha256', $path);
            if (!is_string($live) || !hash_equals(strtolower($hash), strtolower($live))) {
                $out['modified'][] = $rel;
            }
        }
        sort($out['modified']);
        sort($out['missing']);
        return $out;
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
    public static function performUpdate(bool $confirmOverwrite = false): array
    {
        $from = self::currentVersion();

        // HAND-MODIFICATION GUARD (v1.15.0): files a human changed on this
        // install are never overwritten without explicit confirmation. With
        // no installed manifest (pre-1.15) we cannot prove nothing was
        // modified, so the same confirmation is required once. Enforced here
        // server-side regardless of what the UI submitted.
        $local = self::detectLocalModifications();
        $needsConfirm = !$local['manifest']
            || !empty($local['modified'])
            || !empty($local['missing']);
        if ($needsConfirm && !$confirmOverwrite) {
            $what = $local['manifest']
                ? ('Locally modified files detected: ' . implode(', ', array_slice(array_merge($local['modified'], $local['missing']), 0, 10)))
                : 'This install has no release manifest, so local modifications cannot be ruled out';
            return ['ok' => false, 'error' => $what . '. Tick the confirmation box on the update page to proceed (a full backup is made either way).'];
        }

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

        // WHOLE-DIRECTORY SWAP (v1.17.2). The old file-by-file overlay needed
        // write access inside every SUBDIRECTORY of the live module — one
        // root-owned lib/ or language/ folder and the update half-failed
        // after a green preflight. Instead we now assemble the complete new
        // module tree in a sibling staging dir (created by PHP, so PHP owns
        // every file), then swap it into place with two renames. Renames only
        // need write access on modules/servers/ and modules/addons/ — exactly
        // what the preflight verifies — so internal ownership of the OLD tree
        // is irrelevant. Hand-added files in the live tree are carried over
        // (additive contract); the old tree is parked next to the backup.
        $copied = 0;
        $failed = [];
        $parked = [];
        $swapped = [];
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            $src = $stage . '/' . rtrim($prefix, '/');
            if (!is_dir($src)) {
                continue;
            }
            $live = $root . '/' . rtrim($prefix, '/');
            $parent = dirname($live);
            $incoming = $parent . '/.swz-incoming-' . bin2hex(random_bytes(4));

            // 5a. Release tree first — must be complete.
            if (!self::copyTree($src, $incoming)) {
                self::removeTree($incoming);
                $failed[] = $prefix . ' (could not assemble the new tree in ' . $parent . ')';
                continue;
            }
            // 5b. Carry over live files the release does not ship (best-effort:
            //     an unreadable extra is skipped and reported, never fatal).
            self::carryExtras($live, $incoming, $parked);

            // 5c. Swap. Park the old tree beside the backup; roll back the
            //     park if the second rename fails so the module never vanishes.
            $old = $parent . '/.swz-old-' . preg_replace('/[^0-9a-zA-Z.]/', '', $from) . '-' . date('His');
            if (!@rename($live, $old)) {
                self::removeTree($incoming);
                $failed[] = $prefix . ' (could not move the current module aside — is ' . $parent . ' writable?)';
                continue;
            }
            if (!@rename($incoming, $live)) {
                @rename($old, $live); // restore; nothing changed
                self::removeTree($incoming);
                $failed[] = $prefix . ' (could not move the new module into place)';
                continue;
            }
            $copied += self::countFiles($live);
            $swapped[] = $old;
        }
        self::removeTree($stage);

        // Old trees: try to remove; if the old files aren't deletable by PHP
        // (the very ownership mess the swap works around), say where they are.
        $leftovers = [];
        foreach ($swapped as $old) {
            self::removeTree($old);
            if (is_dir($old)) {
                $leftovers[] = $old;
            }
        }

        if (!empty($failed)) {
            self::log('Updater.PartialFailure', [], ['failed' => $failed, 'backup' => $backupDir]);
            return [
                'ok' => false,
                'error' => 'Update not applied for: ' . implode('; ', $failed)
                    . '. Nothing was half-written — each module directory is swapped whole. Backup at ' . $backupDir . '.',
            ];
        }
        $note = '';
        if (!empty($leftovers)) {
            $note = ' The previous version is parked at ' . implode(' and ', $leftovers)
                . ' (PHP may not own those old files) — delete it whenever you like.';
        }
        if (!empty($parked)) {
            $note .= ' Skipped carrying ' . count($parked) . ' unreadable extra file(s): ' . implode(', ', array_slice($parked, 0, 5)) . '.';
        }

        self::writeCache(['ok' => true] + $info + ['checked_at' => 0]); // bust TTL so the banner clears next load
        self::log('Updater.Updated', ['from' => $from, 'to' => $info['version']], ['files' => $copied, 'backup' => $backupDir, 'note' => $note]);

        return [
            'ok' => true,
            'from' => $from,
            'to' => (string) $info['version'],
            'files' => $copied,
            'backup' => $backupDir,
            'note' => $note,
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
                continue;
            }
            // Atomic overlay: stage beside the target, then rename over it.
            // rename() over an existing file needs only DIRECTORY write —
            // exactly what the preflight verifies. A plain copy() also needs
            // write permission on the existing FILE, which fails on installs
            // whose files are owned by a different user than PHP (seen live:
            // preflight green, then "22 file(s) could not be written").
            $tmp = $d . '.swz-new';
            if (copy($s, $tmp) && @rename($tmp, $d)) {
                @chmod($d, 0644);
                $copied++;
            } else {
                @unlink($tmp);
                $failed[] = str_replace(self::whmcsRoot() . '/', '', $d);
            }
        }
    }

    /**
     * Carry files present in the live tree but not in the assembled new tree
     * (hand-added files — the additive contract). Best-effort per file: an
     * unreadable extra is recorded in $skipped, never fatal.
     */
    private static function carryExtras(string $live, string $incoming, array &$skipped): void
    {
        if (!is_dir($live)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($live, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($live) + 1);
            $dst = $incoming . '/' . $rel;
            if ($item->isLink()) {
                continue;
            }
            if ($item->isDir()) {
                if (!is_dir($dst)) {
                    @mkdir($dst, 0755, true);
                }
                continue;
            }
            if (file_exists($dst)) {
                continue; // release version wins
            }
            $dir = dirname($dst);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!@copy($item->getPathname(), $dst)) {
                $skipped[] = $rel;
            }
        }
    }

    /** Count regular files in a tree (for the result summary). */
    private static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $item) {
            if ($item->isFile()) {
                $n++;
            }
        }
        return $n;
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
