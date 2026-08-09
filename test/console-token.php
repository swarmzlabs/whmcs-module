<?php
/**
 * Swarmz WHMCS module — admin CSRF token regression test (offline).
 *
 * WHMCS's generate_token('WHMCS.admin.default') is not guaranteed to return
 * a string: on some installs/configurations it returns null. v1.20.0's
 * catalog-first Credit Packs page passed that value into the string-typed
 * renderPackCatalogView(), so opening the page fataled with a TypeError.
 *
 * This test stubs generate_token() to return null — the exact behaviour of
 * the failing install — and asserts:
 *   1. Console::adminFormToken() exists and returns a string ('' on null),
 *   2. the pack catalog view renders end-to-end with that token.
 *
 * Usage:  php test/console-token.php
 *
 * Like smoke.php, this file stubs the minimum WHMCS surface and is NOT part
 * of the release ZIP — it lives under test/ so production deploys ignore it.
 */

declare(strict_types=1);

namespace WHMCS\Database {
    /** No-op Capsule stand-in; the paths under test never touch the DB. */
    class Capsule
    {
        public static function table(string $name): self
        {
            return new self();
        }

        public function __call($name, $args)
        {
            return $this;
        }
    }
}

namespace {
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }

    /**
     * The regression trigger: WHMCS's real generate_token() returned null on
     * the install that crashed. Keep this returning null — the module must
     * tolerate it everywhere.
     */
    function generate_token($namespace = '')
    {
        return null;
    }

    function logModuleCall($module, $action, $request, $response, $processedResponse = '', $replaceVars = [])
    {
    }

    require_once __DIR__ . '/../modules/addons/swarmz/lib/Console.php';

    $failed = 0;
    $check = function (string $label, bool $ok) use (&$failed) {
        echo str_pad("[$label]", 44, ' '), $ok ? "OK\n" : "FAIL\n";
        if (!$ok) {
            $failed++;
        }
    };

    $console = new \WHMCS\Module\Addon\Swarmz\Console([
        'modulelink' => 'addonmodules.php?module=swarmz',
    ]);
    $ref = new \ReflectionClass($console);

    // 1. adminFormToken() — the single normalization point for the CSRF token.
    $check('adminFormToken.Exists', $ref->hasMethod('adminFormToken'));
    $token = null;
    if ($ref->hasMethod('adminFormToken')) {
        $m = $ref->getMethod('adminFormToken');
        $m->setAccessible(true);
        $token = $m->invoke($console);
        $check('adminFormToken.ReturnsString', is_string($token));
        $check('adminFormToken.EmptyOnNull', $token === '');
    }

    // 2. The Credit Packs catalog view renders with that token — one catalog
    //    pack, one linked mapping, one custom row, one unmapped addon, so every
    //    row branch that concatenates the token actually runs.
    $byCode = [
        'pack_100' => [
            'name' => 'Starter Pack', 'credits' => 100,
            'price_cents' => 500, 'currency' => 'USD', 'cycle' => 'onetime',
        ],
        'pack_500' => [
            'name' => 'Growth Pack', 'credits' => 500,
            'price_cents' => 2000, 'currency' => 'USD', 'cycle' => 'monthly',
        ],
    ];
    $linkedByCode = [
        'pack_100' => [
            'addon_id' => 7, 'name' => 'Starter Pack', 'pack_code' => 'pack_100',
            'pack_name' => 'Starter Pack', 'credits' => 100,
            'hidden' => 0, 'retired' => 0, 'showorder' => 0,
        ],
    ];
    $customRows = [
        [
            'addon_id' => 9, 'name' => 'Hand-typed booster', 'pack_code' => '',
            'pack_name' => '', 'credits' => 250,
            'hidden' => 1, 'retired' => 0, 'showorder' => 0,
        ],
    ];
    $unmappedRows = [
        [
            'addon_id' => 11, 'name' => 'Growth Pack', 'pack_code' => '',
            'pack_name' => '', 'credits' => 0,
            'hidden' => 0, 'retired' => 0, 'showorder' => 1,
        ],
    ];

    try {
        $m = $ref->getMethod('renderPackCatalogView');
        $m->setAccessible(true);
        $html = $m->invoke($console, $byCode, $linkedByCode, $customRows, $unmappedRows, (string) $token);
        $check('packCatalog.Renders', is_string($html) && $html !== '');
        $check('packCatalog.HasPackRow', is_string($html) && strpos($html, 'Starter Pack') !== false);
        $check('packCatalog.HasCustomTable', is_string($html) && strpos($html, 'Custom amounts') !== false);
    } catch (\TypeError $e) {
        $check('packCatalog.Renders', false);
        echo '  TypeError: ', $e->getMessage(), "\n";
    }

    echo "\n";
    if ($failed > 0) {
        echo "FAILED: $failed check(s).\n";
        exit(1);
    }
    echo "All checks passed.\n";
    exit(0);
}
