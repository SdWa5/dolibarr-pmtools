<?php
/* SPDX-License-Identifier: GPL-3.0-or-later */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        // Restler reads @access protected to require authentication on an API class.
        'phpdoc_no_access' => false,
    ])
    ->setFinder($finder);
