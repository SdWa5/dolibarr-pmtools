<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/*
 * Unit tests run without Dolibarr. Integration tests run inside a Dolibarr container with the module
 * mounted as custom/pmtools and DOLIBARR_MAIN_INC pointing at htdocs/master.inc.php.
 */
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../class/pmtoolsvalidator.class.php';
require_once __DIR__.'/../class/mcp/pmtoolsmcpdefinitions.class.php';

$mainInc = getenv('DOLIBARR_MAIN_INC');
if ($mainInc) {
    define('NOSESSION', 1);
    define('NOCSRFCHECK', 1);
    define('NOREQUIREMENU', 1);
    define('NOREQUIREHTML', 1);
    define('NOREQUIREAJAX', 1);
    require_once $mainInc;
    require_once __DIR__.'/../class/pmtoolsservice.class.php';
}
