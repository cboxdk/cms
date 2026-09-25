<?php

declare(strict_types=1);

use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Codecs', 'Contract', 'Postgres', '../packages/*/tests');

// Real Postgres as the app role, schema built by the owner role, no wrapping transaction (GUARDRAILS 9).
pest()->use(RealPostgres::class)->in('Postgres', '../packages/*/tests/Postgres');
