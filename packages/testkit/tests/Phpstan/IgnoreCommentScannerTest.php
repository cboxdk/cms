<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\IgnoreComment;
use Cbox\Cms\Testkit\Phpstan\IgnoreCommentScanner;

/*
 * The token scan behind rule 2. It must find every form of the annotation, on the right line
 * and in the right namespace, and nothing in strings.
 */

/**
 * @return list<string>
 */
function scannedIgnores(string $code): array
{
    return array_map(
        static fn (IgnoreComment $comment): string => sprintf('%d %s %s', $comment->line, $comment->namespace, $comment->tag),
        IgnoreCommentScanner::scan($code),
    );
}

it('finds every form of the annotation with its line and namespace', function (): void {
    $code = <<<'PHP'
        <?php
        // @phpstan-ignore-line
        namespace Cbox\Cms\Core\Entries\Domain;

        /**
         * Text.
         * @phpstan-ignore-next-line
         */
        final class Entry {} # @phpstan-ignore argument.type

        /* @phpstan-ignore return.type */ // @phpstan-ignore-error

        namespace Cbox\Cms\Core\Receipts\Adapter;

        // @phpstan-ignore-next-line
        PHP;

    expect(scannedIgnores($code))->toBe([
        '2  @phpstan-ignore-line',
        '7 Cbox\Cms\Core\Entries\Domain @phpstan-ignore-next-line',
        '9 Cbox\Cms\Core\Entries\Domain @phpstan-ignore',
        '11 Cbox\Cms\Core\Entries\Domain @phpstan-ignore',
        '11 Cbox\Cms\Core\Entries\Domain @phpstan-ignore-error',
        '15 Cbox\Cms\Core\Receipts\Adapter @phpstan-ignore-next-line',
    ]);
});

it('follows braced namespaces and ignores namespace-relative names', function (): void {
    $code = <<<'PHP'
        <?php
        namespace Cbox\Cms\Http\Boundary {
            namespace\parse(); // @phpstan-ignore-line
        }
        namespace {
            // @phpstan-ignore-line
        }
        PHP;

    expect(scannedIgnores($code))->toBe([
        '3 Cbox\Cms\Http\Boundary @phpstan-ignore-line',
        '6  @phpstan-ignore-line',
    ]);
});

it('does not read annotations inside strings', function (): void {
    $code = <<<'PHP'
        <?php
        namespace Cbox\Cms\Core\Entries\Domain;

        $a = '// @phpstan-ignore-line';
        $b = <<<TXT
            /* @phpstan-ignore-next-line */
            TXT;
        PHP;

    expect(IgnoreCommentScanner::scan($code))->toBe([]);
});
