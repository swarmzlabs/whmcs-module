<?php
/**
 * Swarmz WHMCS module — client-area text-direction unit test.
 *
 * Proves Helpers::clientDir() resolves the client's WHMCS language from the
 * same three sources clientLang() uses (session → clientsdetails → global
 * config) and returns 'rtl' only for right-to-left locales. This is the guard
 * behind the RTL panel fix: an Arabic client must get a mirrored panel, an
 * English/German client must stay left-to-right.
 *
 * No WHMCS install needed — we stub the WHMCS constant and load the module's
 * lib directly. Capsule is never touched by clientDir(), so no DB stub is
 * required.
 *
 * Usage:  php test/client-dir.php
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

$root = realpath(__DIR__ . '/../modules/servers/swarmz');
require_once $root . '/lib/Exceptions.php';
require_once $root . '/lib/Api.php';
require_once $root . '/lib/Helpers.php';

use WHMCS\Module\Server\Swarmz\Helpers;

$failed = 0;
$check = static function (string $label, $got, $want) use (&$failed): void {
    $ok = $got === $want;
    if (!$ok) {
        $failed++;
    }
    printf("  %s %s (got %s, want %s)\n", $ok ? '[ok]  ' : '[FAIL]', $label, var_export($got, true), var_export($want, true));
};

// Clean slate — no session, no global.
unset($_SESSION['Language'], $_SESSION['language']);
unset($GLOBALS['CONFIG']);

echo "--- default (no language anywhere) ---\n";
$check('empty params → ltr', Helpers::clientDir([]), 'ltr');

echo "\n--- profile language (clientsdetails) ---\n";
$check('english → ltr', Helpers::clientDir(['clientsdetails' => ['language' => 'english']]), 'ltr');
$check('german → ltr',  Helpers::clientDir(['clientsdetails' => ['language' => 'German']]),  'ltr');
$check('arabic → rtl',  Helpers::clientDir(['clientsdetails' => ['language' => 'arabic']]),  'rtl');
$check('Arabic (mixed case) → rtl', Helpers::clientDir(['clientsdetails' => ['language' => 'Arabic']]), 'rtl');
$check('hebrew → rtl',  Helpers::clientDir(['clientsdetails' => ['language' => 'hebrew']]),  'rtl');
$check('farsi → rtl',   Helpers::clientDir(['clientsdetails' => ['language' => 'farsi']]),   'rtl');
$check('urdu → rtl',    Helpers::clientDir(['clientsdetails' => ['language' => 'urdu']]),    'rtl');

echo "\n--- session wins over profile (the language switcher) ---\n";
$_SESSION['Language'] = 'arabic';
$check('session arabic overrides english profile → rtl',
    Helpers::clientDir(['clientsdetails' => ['language' => 'english']]), 'rtl');
$_SESSION['Language'] = 'english';
$check('session english overrides arabic profile → ltr',
    Helpers::clientDir(['clientsdetails' => ['language' => 'arabic']]), 'ltr');
unset($_SESSION['Language']);

echo "\n--- global config fallback ---\n";
$GLOBALS['CONFIG'] = ['Language' => 'arabic'];
$check('global arabic → rtl', Helpers::clientDir([]), 'rtl');
$GLOBALS['CONFIG'] = ['Language' => 'english'];
$check('global english → ltr', Helpers::clientDir([]), 'ltr');
unset($GLOBALS['CONFIG']);

// An Arabic client now gets the shipped Arabic strings (arabic.php), overlaid
// on the English base so any un-translated key still falls back cleanly.
echo "\n--- clientLang loads Arabic strings for an rtl locale ---\n";
$lang = Helpers::clientLang(['clientsdetails' => ['language' => 'arabic']]);
$check('arabic client gets the Arabic buy_button',
    isset($lang['buy_button']) && $lang['buy_button'] === 'اشترِ المزيد', true);
$check('english base still present under the overlay (workspace_title translated)',
    isset($lang['workspace_title']) && $lang['workspace_title'] === 'مساحة عملك', true);

echo "\n";
if ($failed > 0) {
    echo "FAILED: $failed assertion(s).\n";
    exit(1);
}
echo "All direction assertions passed.\n";
exit(0);
