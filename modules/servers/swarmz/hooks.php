<?php
/**
 * Swarmz WHMCS Module - Action Hooks.
 *
 * Optional WHMCS action hooks. Currently this file is a placeholder — all
 * provisioning logic lives in swarmz.php (the server module). Add hooks here
 * for cross-product behaviour (e.g. ClientAreaPrimaryNavbar overrides) without
 * touching the core module.
 *
 * @copyright Swarmz Labs Ltd.
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('You cannot access this file directly.');
}

// No hooks yet. Example template:
//
// add_hook('AfterModuleCreate', 1, function ($vars) {
//     if (($vars['params']['producttype'] ?? '') !== 'server' ||
//         ($vars['params']['moduletype'] ?? '') !== 'swarmz') {
//         return;
//     }
//     // ...do something post-create...
// });
