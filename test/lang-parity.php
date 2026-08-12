<?php
/**
 * Swarmz WHMCS module — client-area language parity test.
 *
 * english.php is the fallback base (Helpers::clientLang overlays the client's
 * language on top). AGENTS.md requires every user-facing string to ship in
 * ALL language files in the same change. This asserts each translation:
 *   - has EXACTLY the English key set (no missing, no stray keys), and
 *   - preserves every %s placeholder count per key (a dropped %s makes
 *     sprintf mis-substitute in the template).
 *
 * Usage:  php test/lang-parity.php
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

$dir = realpath(__DIR__ . '/../modules/servers/swarmz/language');
$en = include $dir . '/english.php';

$fail = 0;
foreach (['arabic', 'german', 'french', 'italian', 'spanish'] as $lang) {
    $t = include $dir . '/' . $lang . '.php';
    $missing = array_diff(array_keys($en), array_keys($t));
    $extra   = array_diff(array_keys($t), array_keys($en));
    $ph = [];
    foreach ($en as $k => $v) {
        if (substr_count((string) $v, '%s') !== substr_count((string) ($t[$k] ?? ''), '%s')) {
            $ph[] = $k;
        }
    }
    $ok = empty($missing) && empty($extra) && empty($ph);
    if (!$ok) {
        $fail++;
    }
    printf(
        "  %s %s keys=%d missing=%s extra=%s placeholder_mismatch=%s\n",
        $ok ? '[ok]  ' : '[FAIL]',
        str_pad($lang, 8),
        count($t),
        empty($missing) ? 'none' : implode(',', $missing),
        empty($extra) ? 'none' : implode(',', $extra),
        empty($ph) ? 'none' : implode(',', $ph)
    );
}

echo "\n";
if ($fail > 0) {
    echo "FAILED: $fail language file(s) out of sync with english.php.\n";
    exit(1);
}
echo "All language files are in sync.\n";
exit(0);
