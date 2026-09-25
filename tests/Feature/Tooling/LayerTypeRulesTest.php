<?php

declare(strict_types=1);

use Cbox\Cms\Testkit\Phpstan\LayerScope;
use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreCollector;
use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreRule;
use Cbox\Cms\Testkit\Phpstan\TypedArrowFunctionsRule;
use Cbox\Cms\Testkit\Phpstan\TypedClassTagsRule;
use Cbox\Cms\Testkit\Phpstan\TypedClosuresRule;
use Cbox\Cms\Testkit\Phpstan\TypedFunctionsRule;
use Cbox\Cms\Testkit\Phpstan\TypedMethodsRule;
use Cbox\Cms\Testkit\Phpstan\TypedPropertiesRule;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\PhpstanAnalysis;

/*
 * The testkit's PHPStan rules for Boundary and Adapter (GUARDRAILS 2.2, gate 3) through the
 * real configuration: the testkit neon registers them, the root includes it, and
 * vendor/bin/phpstan reports them. The rules themselves are tested with RuleTestCase in
 * packages/testkit/tests/Phpstan.
 */

/**
 * Analyses one probe file with the monorepo configuration, from a temporary directory.
 * Where the file lives does not matter to the rules; its namespace does.
 */
function analyseProbe(string $code): PhpstanAnalysis
{
    $directory = sys_get_temp_dir().'/cms-layer-probe-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $probe = $directory.'/Probe.php';

    try {
        file_put_contents($probe, $code);

        return Phpstan::analyse($probe);
    } finally {
        if (is_file($probe)) {
            unlink($probe);
        }

        rmdir($directory);
    }
}

it('registers every rule and the collector in the testkit neon, which the root includes', function (): void {
    $testkit = (string) file_get_contents(Phpstan::root().'/packages/testkit/config/phpstan.neon');
    $root = (string) file_get_contents(Phpstan::root().'/phpstan.neon');
    $rules = [
        TypedMethodsRule::class,
        TypedFunctionsRule::class,
        TypedClosuresRule::class,
        TypedArrowFunctionsRule::class,
        TypedPropertiesRule::class,
        TypedClassTagsRule::class,
        PhpstanIgnoreRule::class,
    ];

    foreach ($rules as $rule) {
        expect($testkit)->toMatch('/^\s+- '.preg_quote($rule, '/').'$/m');
    }

    expect($testkit)->toMatch('/class: '.preg_quote(PhpstanIgnoreCollector::class, '/').'\s+tags:\s+- phpstan\.collector/')
        ->and($root)->toMatch('/^includes:\s+- vendor\/cboxdk\/cms-testkit\/config\/phpstan\.neon$/m');
});

it('fails the analysis on an untyped array return in the domain', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Domain;

        final class Probe
        {
            public function f(): array
            {
                return [];
            }
        }
        PHP);

    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toEqualCanonicalizing(['missingType.iterableValue', 'cboxCms.untypedArray']);
});

it('reports mixed and the ignore comments that try to hide it, through the real configuration', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Domain;

        final class Probe
        {
            // @phpstan-ignore-next-line
            public function f(mixed $value): int
            {
                return 1; // @phpstan-ignore cboxCms.phpstanIgnore
            }
        }
        PHP);

    // Both rules report despite the comments. PHPStan adds that neither comment matched an
    // error, because non-ignorable errors do not match an ignore comment.
    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toEqualCanonicalizing([
            'cboxCms.mixed',
            'cboxCms.phpstanIgnore',
            'cboxCms.phpstanIgnore',
            'ignore.unmatchedLine',
            'ignore.unmatchedIdentifier',
        ]);
});

it('allows mixed and a precise ignore comment in an Adapter', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Receipts\Adapter;

        final class Probe
        {
            public function f(mixed $value): mixed
            {
                // @phpstan-ignore method.nonObject
                return $value->run();
            }
        }
        PHP);

    expect($analysis->exitCode)->toBe(0)
        ->and($analysis->identifiers)->toBe([]);
});

it('uses the same layer names and the same innermost-segment reading as the Arch suite', function (string $namespace): void {
    $layer = Layer::of($namespace);

    expect(LayerScope::LAYERS)->toBe(array_map(static fn (Layer $case): string => $case->value, Layer::cases()))
        ->and(LayerScope::allowsLooseTypes($namespace))
        ->toBe(in_array($layer, [Layer::Boundary, Layer::Adapter], true));
})->with([
    'Cbox\Cms\Core\Entries\Domain',
    'Cbox\Cms\Core\Entries\Domain\Dto',
    'Cbox\Cms\Core\Entries\Actions',
    'Cbox\Cms\Core\Receipts\Adapter\Postgres',
    'Cbox\Cms\Core\Entries\Infrastructure\Models',
    'Cbox\Cms\Http',
    'Cbox\Cms\Http\Boundary',
    'Cbox\Cms\Http\Boundary\Parsers',
    'Cbox\Cms\Core\Boundary\Domain',
    'Cbox\Cms\Core\Adapter\Jobs',
    'Cbox\Cms\Cli\Console',
    'Cbox\Cms\Contracts',
    'Cbox\Cms\Core\DomainEvents',
    '',
]);
