<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\MarkerScan;
use Cbox\Cms\Tests\Support\Arch\Rules;

/*
 * The marker gate of GUARDRAILS 11: code and configuration carry none of the five marker words
 * of the definition of done. It reads what git lists in the checkout, tracked or new, so the gate
 * runs in gate 5 of composer check and of bin/ci's PR profile. tests/Feature/Tooling/MarkerGateTest.php
 * covers the selection, the matching and the exclusions.
 */

arch('markers: no code or configuration file carries a marker word of GUARDRAILS 11', function (): void {
    $scan = MarkerScan::of(Codebase::root());

    expect($scan->files)->toContain(
        'composer.json',
        'package.json',
        'phpunit.xml',
        'phpstan.neon',
        'compose.yaml',
        'docker/ci.Dockerfile',
        '.github/workflows/ci.yml',
        'bin/ci',
        'docker/ci-entry.sh',
        'docker/postgres/initdb.d/10-cms.sh',
        'tests/Support/Arch/MarkerScan.php',
        'tests/Arch/MarkersTest.php',
    )->and($scan->files)->not->toContain('composer.lock', 'package-lock.json', 'CLAUDE.md', '.claude/workflows/cms-milestone.js');

    Rules::none($scan->hits, 'Code and configuration may not carry a marker word (GUARDRAILS 11). Finish the work or record it in PROGRESS.md:');
});
