<?php

declare(strict_types=1);

use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\TestCase;
use Composer\InstalledVersions;
use PHPUnit\Util\ExcludeList;

// Testbench resolves the package root, and with it testbench.yaml and workbench/routes, from
// Composer's root package unless this constant is defined. A test that loads Rector's bundled
// autoloader registers a second root package, so every later test in the process would look for
// the workbench inside vendor/rector/rector. vendor/bin/testbench defines the constant the same way.
if (! defined('TESTBENCH_WORKING_PATH')) {
    define('TESTBENCH_WORKING_PATH', dirname(__DIR__));
}

// PHPUnit 13 records a PHP warning raised outside a test as a warning of the run, also when the @
// operator suppressed it, unless the file lies in a directory on its exclude list; Pest puts its
// own code there. Before the first browser test the browser plugin empties tests/Browser/Screenshots
// with @rmdir() on subdirectories that need not exist, so under failOnWarning every browser run
// after one that left a screenshot exited 1 without printing why. The plugin joins Pest on the
// list. Only suppressed warnings are dropped this way: an unsuppressed warning from the plugin
// still fails the run. BrowserToolchainTest covers it.
$browserPlugin = InstalledVersions::getInstallPath('pestphp/pest-plugin-browser')
    ?? throw new RuntimeException('Composer does not know where pestphp/pest-plugin-browser is installed.');

ExcludeList::addDirectory($browserPlugin.'/src');

pest()->extend(TestCase::class)->in('Feature', 'Codecs', 'Contract', 'Postgres', 'Browser', '../packages/*/tests');

// Real Postgres as the app role, schema built by the owner role, no wrapping transaction (GUARDRAILS 9).
pest()->use(RealPostgres::class)->in('Postgres', '../packages/*/tests/Postgres');

// Real Valkey on the test database index, with a key prefix per run that is cleaned after each test.
pest()->use(RealValkey::class)->in('Postgres', '../packages/*/tests/Postgres');
