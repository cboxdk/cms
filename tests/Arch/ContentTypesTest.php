<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\ContentTypeScan;
use Cbox\Cms\Tests\Support\Arch\Rules;

/*
 * The kernel knows no content types (GUARDRAILS 2.4): the src of the core packages never names a
 * type, a field or a select value of the workbench's fixture schema. A module or an addon owns
 * its types and reaches them through the code generated from its schema. The scan reads string
 * literals, heredocs and identifiers but not comments; tests/Feature/Tooling/ContentTypeScanTest.php
 * covers what it reads and matches.
 */

arch('content types: the core packages name no type, field or select value of the fixture schema', function (): void {
    $root = Codebase::root();
    $scan = ContentTypeScan::of($root, ContentTypeScan::handlesBelow($root.'/'.ContentTypeScan::SCHEMA));
    $packages = array_values(array_unique(array_map(
        static fn (string $file): string => explode('/', $file)[1],
        $scan->files,
    )));
    sort($packages, SORT_STRING);
    $expected = ContentTypeScan::PACKAGES;
    sort($expected, SORT_STRING);

    expect($scan->handles)->toContain('fixture_article', 'fixture_body', 'fixture_title', 'fixture_measurement', 'fixture_reading', 'fixture_scale', 'fixture_celsius', 'fixture_kelvin', 'fixture_measured_at', 'fixture_station', 'fixture_note')
        ->and(ContentTypeScan::ordinary($scan->handles))->toBe([], 'Every handle of the fixture schema starts with '.ContentTypeScan::PREFIX.', so that it is not an ordinary word in code.')
        ->and($packages)->toBe($expected)
        ->and($scan->files)->toContain('packages/core/src/CoreServiceProvider.php', 'packages/testkit/src/Idempotency/IdempotencyStoreContract.php');

    Rules::none($scan->hits, 'The core packages may not name a type, a field or a select value of the fixture schema (GUARDRAILS 2.4). Read types from the schema instead:');
});
