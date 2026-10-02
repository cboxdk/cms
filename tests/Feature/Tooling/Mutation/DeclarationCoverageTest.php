<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tooling\Mutation\Domain\DeclarationCoverage;

/*
 * PCOV never covers a class constant, an enum case, a property or an attribute, so
 * pest-plugin-mutate reported every mutation of one as uncovered, whatever the tests asserted: a
 * class of constants alone scored 0. DeclarationCoverage gives those lines the tests that run the
 * code that reads them. tests/Mutation runs it for real.
 */

const DECLARING_SOURCE = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Acme;

    #[Marker(1)]
    final readonly class Limits
    {
        public const int MAX = 10;

        public const array WORDS = [
            'one',
            'two',
        ];

        public int $count = 3;

        public static function words(): array
        {
            return self::WORDS;
        }
    }
    PHP;

const READING_SOURCE = <<<'PHP'
    <?php

    namespace Acme;

    final class Reader
    {
        public function max(): int
        {
            return Limits::MAX;
        }

        public function other(): int
        {
            return OtherLimits::MAX;
        }
    }
    PHP;

const CONSTANTS_ONLY_SOURCE = <<<'PHP'
    <?php

    namespace Acme;

    enum Level: int
    {
        case Low = 1;
        case High = 2;
    }
    PHP;

it('finds the lines of the constants, enum cases, properties and attributes of a source', function (): void {
    expect(DeclarationCoverage::declarationLines(DECLARING_SOURCE))->toBe([7, 10, 12, 13, 14, 15, 17])
        ->and(DeclarationCoverage::declarationLines(CONSTANTS_ONLY_SOURCE))->toBe([7, 8])
        ->and(DeclarationCoverage::declarationLines(READING_SOURCE))->toBe([])
        ->and(DeclarationCoverage::classNames(DECLARING_SOURCE))->toBe(['Limits'])
        ->and(DeclarationCoverage::classNames(CONSTANTS_ONLY_SOURCE))->toBe(['Level']);
});

it('gives the declarations the tests of their own file and of the lines that name the class', function (): void {
    $coverage = [
        '/src/Limits.php' => [21 => [0 => 1], 10 => null],
        '/src/Reader.php' => [9 => [1 => 1, 2 => 1], 14 => [3 => 1]],
    ];

    $attributed = DeclarationCoverage::attribute(
        $coverage,
        ['/src/Limits.php' => DECLARING_SOURCE, '/src/Reader.php' => READING_SOURCE],
        ['/src/Limits.php'],
    );

    expect($attributed['/src/Limits.php'][10])->toBe([0 => 1, 1 => 1, 2 => 1])
        ->and($attributed['/src/Limits.php'][7])->toBe([0 => 1, 1 => 1, 2 => 1])
        ->and($attributed['/src/Limits.php'][13])->toBe([0 => 1, 1 => 1, 2 => 1])
        ->and($attributed['/src/Limits.php'][21])->toBe([0 => 1])
        ->and($attributed['/src/Reader.php'])->toBe($coverage['/src/Reader.php']);
});

it('covers a file of declarations alone through the lines of other files that name it', function (): void {
    $source = str_replace('Limits::MAX', 'Level::Low', READING_SOURCE);
    $coverage = ['/src/Reader.php' => [9 => [4 => 1], 14 => [5 => 1]]];

    $attributed = DeclarationCoverage::attribute($coverage, ['/src/Reader.php' => $source, '/src/Level.php' => CONSTANTS_ONLY_SOURCE], ['/src/Level.php']);

    expect($attributed['/src/Level.php'])->toBe([7 => [4 => 1], 8 => [4 => 1]]);
});

it('leaves the coverage alone for a source without declarations, or whose declarations no test reaches', function (): void {
    $coverage = ['/src/Reader.php' => [9 => [1 => 1]]];

    expect(DeclarationCoverage::attribute($coverage, ['/src/Reader.php' => READING_SOURCE], ['/src/Reader.php']))->toBe($coverage)
        ->and(DeclarationCoverage::attribute($coverage, ['/src/Reader.php' => READING_SOURCE, '/src/Level.php' => CONSTANTS_ONLY_SOURCE], ['/src/Level.php']))->toBe($coverage)
        ->and(DeclarationCoverage::attribute($coverage, [], ['/src/Missing.php']))->toBe($coverage);
});

it('adds the tests to a declaration line that a test already covered, as PCOV marks a constant PHP evaluates at run time for the first test that reads it', function (): void {
    // Regression (M1-T66): a list of names that spreads another constant was marked for the one
    // test that first read it in each process, so its mutations never ran the tests that check it.
    $coverage = [
        '/src/Limits.php' => [21 => [0 => 1], 7 => [5 => 1]],
        '/src/Reader.php' => [9 => [1 => 1, 2 => 1]],
    ];

    $attributed = DeclarationCoverage::attribute(
        $coverage,
        ['/src/Limits.php' => DECLARING_SOURCE, '/src/Reader.php' => READING_SOURCE],
        ['/src/Limits.php'],
    );

    expect($attributed['/src/Limits.php'][7])->toBe([0 => 1, 1 => 1, 2 => 1, 5 => 1])
        ->and($attributed['/src/Limits.php'][10])->toBe([0 => 1, 1 => 1, 2 => 1, 5 => 1])
        ->and($attributed['/src/Limits.php'][21])->toBe([0 => 1]);
});
