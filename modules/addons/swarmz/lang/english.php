<?php
/**
 * Swarmz Reseller Console — English language strings.
 *
 * The console renders most copy inline (see lib/Console.php); these keys exist
 * so WHMCS has a language file to load for the module label and any future
 * translatable strings.
 *
 * choose_* (v1.26.0): the CUSTOMER-facing workspace chooser page
 * (templates/choose.tpl), shown when the host's "Existing Customer
 * Workspace" policy is `ask`. Keep it unbranded — this renders inside the
 * host's client area. A lang/<language>.php sibling may translate any key;
 * swarmz_clientarea() overlays it on this English base so a missing key
 * never blanks the page.
 */

$_ADDONLANG['title'] = 'Swarmz Reseller Console';
$_ADDONLANG['dashboard'] = 'Dashboard';
$_ADDONLANG['customer'] = 'Customer';
$_ADDONLANG['plan'] = 'Plan';
$_ADDONLANG['credits'] = 'Credits';
$_ADDONLANG['wholesale'] = 'Wholesale';

// Workspace chooser (customer-facing, unbranded)
$_ADDONLANG['choose_title'] = 'Where should we build this?';
$_ADDONLANG['choose_lede'] = 'You have more than one workspace. Pick the one to continue in, or start a fresh one.';
$_ADDONLANG['choose_building'] = 'Building:';
$_ADDONLANG['choose_since'] = 'Since';
$_ADDONLANG['choose_last_opened'] = 'last opened';
$_ADDONLANG['choose_build_here'] = 'Build it here';
$_ADDONLANG['choose_none'] = 'You have no active workspace yet.';
$_ADDONLANG['choose_new_lede'] = 'Prefer a clean slate?';
$_ADDONLANG['choose_new'] = 'Start a new workspace';
$_ADDONLANG['choose_error'] = 'That workspace could not be opened just now. Please try again, or pick another one.';
