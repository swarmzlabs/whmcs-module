<?php
// Standalone harness for Updater's pure logic (no WHMCS runtime needed).
define('WHMCS', true);
require __DIR__ . '/../modules/addons/swarmz/lib/Updater.php';

use WHMCS\Module\Addon\Swarmz\Updater;

$call = function (string $method, ...$args) {
    $m = new ReflectionMethod(Updater::class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
};

// ── entryAllowed: the security filter ────────────────────────────────
$good = [
    'modules/', 'modules/servers/', 'modules/servers/swarmz/',
    'modules/servers/swarmz/swarmz.php',
    'modules/servers/swarmz/templates/overview.tpl',
    'modules/addons/swarmz/lib/Updater.php',
];
$bad = [
    '', '/etc/passwd', 'modules/servers/swarmz/../evil.php',
    'modules/servers/other/x.php', 'modules/addons/other/x.php',
    'index.php', 'configuration.php', 'modules/servers/swarmzevil/x.php',
    "modules/servers/swarmz/\0.php", 'modules\\servers\\swarmz\\x.php',
    'templates/orderforms/x.tpl', '../../modules/servers/swarmz/x.php',
];
$fail = 0;
foreach ($good as $e) { if (!$call('entryAllowed', $e)) { echo "FAIL should-allow: $e\n"; $fail++; } }
foreach ($bad as $e)  { if ($call('entryAllowed', $e))  { echo "FAIL should-block: " . addcslashes($e, "\0..\37") . "\n"; $fail++; } }
echo $fail === 0 ? "entryAllowed: all " . (count($good)+count($bad)) . " cases pass\n" : "entryAllowed: $fail FAILURES\n";

// ── live release feed parse (network) ───────────────────────────────
$info = $call('fetchLatestRelease');
echo "feed ok=" . var_export($info['ok'] ?? null, true)
   . " version=" . ($info['version'] ?? '-')
   . " sha256=" . (isset($info['sha256']) && strlen($info['sha256']) === 64 ? 'present(64)' : 'MISSING')
   . " zip=" . (!empty($info['zip_url']) ? 'present' : 'MISSING') . "\n";

// ── version compare semantics ───────────────────────────────────────
echo "updateAvailable(vs current " . Updater::currentVersion() . ", latest " . ($info['version'] ?? '-') . "): "
   . var_export(version_compare((string)($info['version'] ?? '0'), '1.14.0', '>'), true) . " (expect false once 1.14.0 is released)\n";
