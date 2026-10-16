<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

/**
 * Restler only demands authentication for an API class tagged @access protected. A code style fix
 * once stripped the tag, so its presence is guarded here.
 */
final class ApiAccessTagTest extends TestCase
{
    public function testApiClassRequiresAuthentication(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../class/api_pmtools.class.php');
        $this->assertMatchesRegularExpression('/\*\s+@access protected\s*\n(?:\s*\*.*\n)*?\s*\*\s+@class\s+DolibarrApiAccess/', $source);
    }
}
