<?php

declare(strict_types=1);

use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\TestCase;

// Testbench resolves the package root, and with it testbench.yaml and workbench/routes, from
// Composer's root package unless this constant is defined. A test that loads Rector's bundled
// autoloader registers a second root package, so every later test in the process would look for
// the workbench inside vendor/rector/rector. vendor/bin/testbench defines the constant the same way.
if (! defined('TESTBENCH_WORKING_PATH')) {
    define('TESTBENCH_WORKING_PATH', dirname(__DIR__));
}

pest()->extend(TestCase::class)->in('Feature', 'Codecs', 'Contract', 'Postgres', 'Browser', '../packages/*/tests');

// Real Postgres as the app role, schema built by the owner role, no wrapping transaction (GUARDRAILS 9).
pest()->use(RealPostgres::class)->in('Postgres', '../packages/*/tests/Postgres');

// Real Valkey on the test database index, with a key prefix per run that is cleaned after each test.
pest()->use(RealValkey::class)->in('Postgres', '../packages/*/tests/Postgres');
