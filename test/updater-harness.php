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

// ── hand-modification guard (v1.15.0) ───────────────────────────────
// Build a fake WHMCS root with a manifest, tamper with it, and assert
// detection + the server-side refusal (which must fire BEFORE any network).
$root = sys_get_temp_dir() . '/swz-guard-' . bin2hex(random_bytes(4));
mkdir($root . '/modules/addons/swarmz', 0755, true);
mkdir($root . '/modules/servers/swarmz/templates', 0755, true);
file_put_contents($root . '/modules/servers/swarmz/templates/overview.tpl', "original tpl\n");
file_put_contents($root . '/modules/servers/swarmz/swarmz.php', "<?php // original\n");
file_put_contents($root . '/modules/addons/swarmz/swarmz.php', "<?php // addon\n");
$files = [];
foreach ([
    'modules/servers/swarmz/templates/overview.tpl',
    'modules/servers/swarmz/swarmz.php',
    'modules/addons/swarmz/swarmz.php',
] as $rel) {
    $files[$rel] = hash_file('sha256', $root . '/' . $rel);
}
file_put_contents($root . '/modules/addons/swarmz/release-manifest.json',
    json_encode(['version' => '9.9.8', 'files' => $files]));

// Point the Updater at the fake root (ROOTDIR is consulted first).
define('ROOTDIR', $root);

$clean = Updater::detectLocalModifications();
echo "guard clean-state: manifest=" . var_export($clean['manifest'], true)
   . " modified=" . count($clean['modified']) . " missing=" . count($clean['missing'])
   . " (expect true/0/0)\n";

// Hand-edit the template + delete a file.
file_put_contents($root . '/modules/servers/swarmz/templates/overview.tpl', "CUSTOMIZED BY HOST\n");
unlink($root . '/modules/addons/swarmz/swarmz.php');

$dirty = Updater::detectLocalModifications();
$okDetect = $dirty['modified'] === ['modules/servers/swarmz/templates/overview.tpl']
    && $dirty['missing'] === ['modules/addons/swarmz/swarmz.php'];
echo "guard detection: " . ($okDetect ? "exact (1 modified + 1 deleted)" : "FAIL " . json_encode($dirty)) . "\n";

// Refusal without confirmation — must fail closed with no network involved.
$res = Updater::performUpdate(false);
$refused = empty($res['ok']) && strpos((string) $res['error'], 'overview.tpl') !== false
    && strpos((string) $res['error'], 'confirmation') !== false;
echo "guard refusal (no confirm): " . ($refused ? "refused, names the file" : "FAIL " . json_encode($res)) . "\n";

// No-manifest fallback: also requires confirmation.
unlink($root . '/modules/addons/swarmz/release-manifest.json');
$res2 = Updater::performUpdate(false);
$refused2 = empty($res2['ok']) && stripos((string) $res2['error'], 'manifest') !== false;
echo "guard refusal (no manifest): " . ($refused2 ? "refused with explanation" : "FAIL " . json_encode($res2)) . "\n";
