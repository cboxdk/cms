<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Testkit\Phpstan\EventPayloadTextRule;
use Cbox\Cms\Testkit\Phpstan\FunctionCallablesRule;
use Cbox\Cms\Testkit\Phpstan\HookIoRule;
use Cbox\Cms\Testkit\Phpstan\InternalClassConstantUsageExtension;
use Cbox\Cms\Testkit\Phpstan\InternalClassNameUsageExtension;
use Cbox\Cms\Testkit\Phpstan\InternalMethodUsageExtension;
use Cbox\Cms\Testkit\Phpstan\InternalUseCollector;
use Cbox\Cms\Testkit\Phpstan\InternalUseIgnoreErrorExtension;
use Cbox\Cms\Testkit\Phpstan\InternalUseRule;
use Cbox\Cms\Testkit\Phpstan\KernelTableWriteRule;
use Cbox\Cms\Testkit\Phpstan\LayerScope;
use Cbox\Cms\Testkit\Phpstan\MethodCallablesRule;
use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreCollector;
use Cbox\Cms\Testkit\Phpstan\PhpstanIgnoreRule;
use Cbox\Cms\Testkit\Phpstan\RawSqlRule;
use Cbox\Cms\Testkit\Phpstan\SavepointStringsRule;
use Cbox\Cms\Testkit\Phpstan\StaticMethodCallablesRule;
use Cbox\Cms\Testkit\Phpstan\StringIdsRule;
use Cbox\Cms\Testkit\Phpstan\SystemClockRule;
use Cbox\Cms\Testkit\Phpstan\TransactionCallsRule;
use Cbox\Cms\Testkit\Phpstan\TypedArrowFunctionsRule;
use Cbox\Cms\Testkit\Phpstan\TypedClassConstantsRule;
use Cbox\Cms\Testkit\Phpstan\TypedClassTagsRule;
use Cbox\Cms\Testkit\Phpstan\TypedClosuresRule;
use Cbox\Cms\Testkit\Phpstan\TypedFunctionsRule;
use Cbox\Cms\Testkit\Phpstan\TypedMethodsRule;
use Cbox\Cms\Testkit\Phpstan\TypedPropertiesRule;
use Cbox\Cms\Testkit\Phpstan\UuidCreationRule;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\PhpstanAnalysis;

/*
 * The testkit's PHPStan rules (GUARDRAILS 2.2, 2.3, 4.1 and 6, PRD 4.2 and 5.3, gate 3) through the
 * real configuration: the testkit neon registers them, the root includes it, and
 * vendor/bin/phpstan reports them. The rules themselves are tested with RuleTestCase in
 * packages/testkit/tests/Phpstan.
 */

/**
 * Analyses one probe file with the monorepo configuration, from a temporary directory.
 * Where the file lives does not matter to the rules; its namespace does.
 */
function analyseProbe(string $code, ?string $parameters = null): PhpstanAnalysis
{
    $directory = sys_get_temp_dir().'/cms-layer-probe-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $probe = $directory.'/Probe.php';
    $configuration = $directory.'/phpstan.neon';

    try {
        file_put_contents($probe, $code);

        if ($parameters === null) {
            return Phpstan::analyse($probe);
        }

        // An addon's configuration: the shared one, through the monorepo's, and its own
        // parameters. %currentWorkingDirectory% is the monorepo root, where PHPStan runs.
        file_put_contents($configuration, "includes:\n    - %currentWorkingDirectory%/phpstan.neon\n\nparameters:\n".$parameters);

        return Phpstan::analyse($probe, $configuration);
    } finally {
        foreach ([$probe, $configuration] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($directory);
    }
}

/**
 * An addon class in the given namespace that calls a method of LayerScope, an #[Internal] class
 * of the testkit, on the line with the comment, or below it when the comment has a line of its
 * own. A probe with a comment of its own has no trailing comment.
 */
function internalUseProbe(string $namespace, string $trailing = '', string $above = ''): string
{
    $above = $above === '' ? '' : "\n        {$above}";
    $trailing = $trailing === '' ? '' : " {$trailing}";

    return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Cbox\\Cms\\Testkit\\Phpstan\\LayerScope;

        final readonly class Probe
        {
            public function f(): bool
            {{$above}
                return LayerScope::isTestCode('Acme');{$trailing}
            }
        }
        PHP;
}

it('registers every rule, the collector and the extensions in the testkit neon, which the root includes', function (): void {
    $testkit = (string) file_get_contents(Phpstan::root().'/packages/testkit/config/phpstan.neon');
    $root = (string) file_get_contents(Phpstan::root().'/phpstan.neon');
    $rules = [
        TypedMethodsRule::class,
        TypedFunctionsRule::class,
        TypedClosuresRule::class,
        TypedArrowFunctionsRule::class,
        TypedPropertiesRule::class,
        TypedClassConstantsRule::class,
        TypedClassTagsRule::class,
        PhpstanIgnoreRule::class,
        TransactionCallsRule::class,
        SavepointStringsRule::class,
        StringIdsRule::class,
        RawSqlRule::class,
        SystemClockRule::class,
        UuidCreationRule::class,
        FunctionCallablesRule::class,
        MethodCallablesRule::class,
        StaticMethodCallablesRule::class,
        InternalUseRule::class,
        EventPayloadTextRule::class,
        HookIoRule::class,
        KernelTableWriteRule::class,
    ];
    $services = [
        PhpstanIgnoreCollector::class => 'phpstan.collector',
        InternalUseCollector::class => 'phpstan.collector',
        InternalUseIgnoreErrorExtension::class => 'phpstan.ignoreErrorExtension',
        InternalClassNameUsageExtension::class => 'phpstan.restrictedClassNameUsageExtension',
        InternalMethodUsageExtension::class => 'phpstan.restrictedMethodUsageExtension',
        InternalClassConstantUsageExtension::class => 'phpstan.restrictedClassConstantUsageExtension',
    ];

    foreach ($rules as $rule) {
        expect($testkit)->toMatch('/^\s+- '.preg_quote($rule, '/').'$/m');
    }

    foreach ($services as $service => $tag) {
        expect($testkit)->toMatch('/class: '.preg_quote($service, '/').'\s+tags:\s+- '.preg_quote($tag, '/').'$/m');
    }

    expect($root)->toMatch('/^includes:\s+- packages\/testkit\/config\/phpstan\.neon$/m');
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

it('fails the analysis on a const array without a typed @var in the domain, which PHPStan alone lets pass', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Domain;

        final class Probe
        {
            public const array WORDS = ['SELECT', 'INSERT'];

            /** @var list<string> */
            public const array TYPED = ['SELECT', 'INSERT'];
        }
        PHP);

    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toBe(['cboxCms.untypedArray']);
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

it('fails the analysis on a transaction call and a SAVEPOINT statement in an action', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Actions;

        use Illuminate\Support\Facades\DB;

        final readonly class Probe
        {
            public function f(): void
            {
                DB::transaction(static function (): void {
                    DB::statement('SAVEPOINT chunk');
                });
            }
        }
        PHP);

    expect($analysis->exitCode)->not->toBe(0)
        // DB::statement() is also raw SQL outside Infrastructure and Adapter (GUARDRAILS 6).
        ->and($analysis->identifiers)->toEqualCanonicalizing(['cboxCms.transaction', 'cboxCms.savepoint', 'cboxCms.rawSql']);
});

it('allows the same transaction calls in an Adapter', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Adapter;

        use Illuminate\Support\Facades\DB;

        final readonly class Probe
        {
            public function f(): void
            {
                DB::transaction(static function (): void {
                    DB::statement('SAVEPOINT chunk');
                });
            }
        }
        PHP);

    expect($analysis->exitCode)->toBe(0)
        ->and($analysis->identifiers)->toBe([]);
});

it('fails the analysis on raw SQL in the domain and allows it in Infrastructure and an Adapter', function (string $layer, bool $allowed): void {
    $analysis = analyseProbe(<<<PHP
        <?php

        declare(strict_types=1);

        namespace Cbox\\Cms\\Core\\Entries\\{$layer};

        use Illuminate\\Database\\ConnectionInterface;

        final readonly class Probe
        {
            public function __construct(private ConnectionInterface \$connection) {}

            public function f(): int
            {
                return \$this->connection->table('entries')->whereRaw('id > 0')->count();
            }
        }
        PHP);

    if ($allowed) {
        expect($analysis->exitCode)->toBe(0)
            ->and($analysis->identifiers)->toBe([]);

        return;
    }

    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toBe(['cboxCms.rawSql']);
})->with([
    'Domain' => ['Domain', false],
    'Boundary' => ['Boundary', false],
    'Infrastructure' => ['Infrastructure', true],
    'Adapter' => ['Adapter', true],
]);

it('fails the analysis on a public string id in the domain', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Changesets\Domain;

        interface Probe
        {
            public function find(string $changesetId): ?self;
        }
        PHP);

    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toBe(['cboxCms.stringId']);
});

it('fails the analysis on a string property of an event payload, despite an ignore comment, and passes its value objects', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Stock\Domain;

        use Cbox\Cms\Contracts\Events\EventData;
        use Cbox\Cms\Contracts\Events\EventDatum;
        use Cbox\Cms\Contracts\Events\EventPayload;
        use Cbox\Cms\Contracts\Events\TextHash;
        use Cbox\Cms\Contracts\Ids\ChangesetId;

        final readonly class Probe implements EventPayload
        {
            public function __construct(
                public ChangesetId $changeset,
                public TextHash $titleHash,
                public string $title, // @phpstan-ignore cboxCms.eventPayloadText
            ) {}

            public function data(): EventData
            {
                return EventData::empty()
                    ->with('changeset', EventDatum::identifier($this->changeset))
                    ->with('title_hash', EventDatum::hash($this->titleHash));
            }
        }
        PHP);

    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toContain('cboxCms.eventPayloadText');
});

it('fails the analysis on IO in a hook and in a trait it uses, despite ignore comments, and passes the same IO in a class that is no hook', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Hooks\Domain;

        use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
        use Cbox\Cms\Contracts\Hooks\HookDecision;
        use Cbox\Cms\Contracts\Hooks\PlanView;
        use Illuminate\Support\Facades\DB;

        trait ReadsFiles
        {
            public function read(): string|false
            {
                return file_get_contents('/tmp/value'); // @phpstan-ignore cboxCms.hookIo
            }
        }

        final readonly class Probe implements AuthorizeHook
        {
            use ReadsFiles;

            public function authorize(PlanView $plan): HookDecision
            {
                // @phpstan-ignore-next-line
                return DB::table('shop_rules')->exists() ? HookDecision::deny('ruled') : HookDecision::noObjection();
            }
        }

        final readonly class NotAHook
        {
            use ReadsFiles;
        }
        PHP);

    expect($analysis->exitCode)->not->toBe(0)
        ->and(array_values(array_filter($analysis->identifiers, static fn (string $identifier): bool => $identifier === HookIoRule::IDENTIFIER)))->toBe([HookIoRule::IDENTIFIER, HookIoRule::IDENTIFIER, HookIoRule::IDENTIFIER]);
});

it('fails the analysis on a write to a kernel table in an addon, despite an ignore comment, and allows it in the fixture writers', function (string $namespace, bool $allowed): void {
    $analysis = analyseProbe(<<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Illuminate\Database\ConnectionInterface;

        final readonly class Probe
        {
            public const string TABLE = 'nodes';

            public function __construct(private ConnectionInterface \$connection) {}

            public function write(): void
            {
                \$this->connection->table(self::TABLE)->where('id', 'a')->update(['version' => 2]); // @phpstan-ignore cboxCms.kernelTableWrite
            }
        }
        PHP);

    expect(in_array(KernelTableWriteRule::IDENTIFIER, $analysis->identifiers, true))->toBe(! $allowed);
})->with([
    'an addon adapter' => ['Acme\Shop\Adapter', false],
    'the testkit outside the fixture writers' => ['Cbox\Cms\Testkit\Seeding\Adapter', false],
    'the fixture writers' => ['Cbox\Cms\Testkit\FixtureWriters\Seeding\Adapter', true],
    'the core' => ['Cbox\Cms\Core\Structure\Adapter', true],
]);

it('passes a string id a framework interface requires, also through a parent class, and fails the same name without it', function (string $implements, bool $allowed): void {
    $analysis = analyseProbe(<<<PHP
        <?php

        declare(strict_types=1);

        namespace Cbox\\Cms\\Core\\Sessions\\Domain;

        abstract class SessionIds {$implements}
        {
        }

        abstract class Probe extends SessionIds
        {
            public function validateId(string \$id): bool
            {
                return \$id !== '';
            }
        }
        PHP);

    if ($allowed) {
        expect($analysis->exitCode)->toBe(0)
            ->and($analysis->identifiers)->toBe([]);

        return;
    }

    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toBe(['cboxCms.stringId']);
})->with([
    "PHP's session interface" => ['implements \SessionUpdateTimestampHandlerInterface', true],
    'no interface' => ['', false],
]);

it('fails the analysis on reading the system clock and making a UUID in an adapter, despite ignore comments', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Adapter;

        use DateTimeImmutable;
        use Illuminate\Support\Str;

        final readonly class Probe
        {
            public function stamp(): string
            {
                // @phpstan-ignore cboxCms.systemClock
                $now = new DateTimeImmutable();

                // @phpstan-ignore cboxCms.uuid
                return $now->format('c').Str::uuid()->toString();
            }
        }
        PHP);

    // PHPStan adds that neither comment matched an error, because non-ignorable errors do not
    // match an ignore comment.
    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toEqualCanonicalizing([
            'cboxCms.systemClock',
            'cboxCms.uuid',
            'ignore.unmatchedIdentifier',
            'ignore.unmatchedIdentifier',
        ]);
});

it('allows the system clock in a Clock implementation and UUIDs in an IdGenerator implementation', function (): void {
    $analysis = analyseProbe(<<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Blog\Adapter;

        use Cbox\Cms\Contracts\Clock;
        use Cbox\Cms\Contracts\IdGenerator;
        use Cbox\Cms\Contracts\Ids\Uuid7;
        use DateTimeImmutable;
        use DateTimeZone;
        use Illuminate\Support\Str;

        final readonly class ProbeClock implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('now', new DateTimeZone('UTC'));
            }
        }

        final readonly class ProbeIds implements IdGenerator
        {
            public function next(): Uuid7
            {
                return new Uuid7(Str::uuid7()->toString());
            }
        }
        PHP);

    expect($analysis->exitCode)->toBe(0)
        ->and($analysis->identifiers)->toBe([]);
});

it('fails the analysis when an addon uses an internal class of the testkit, and allows it in the core', function (string $namespace, bool $allowed): void {
    $analysis = analyseProbe(<<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Cbox\\Cms\\Testkit\\Phpstan\\LayerScope;

        final readonly class Probe
        {
            public function f(): bool
            {
                return LayerScope::isTestCode('Acme');
            }
        }
        PHP);

    expect($analysis->exitCode === 0)->toBe($allowed)
        ->and($analysis->identifiers)->toBe($allowed ? [] : ['cboxCms.internalUse']);
})->with([
    'an addon' => ['Acme\Blog', false],
    'the core' => ['Cbox\Cms\Core\Entries', true],
]);

it('hides an internal use in an addon Adapter only with an ignore comment that names cboxCms.internalUse', function (string $trailing, string $above, array $identifiers): void {
    $analysis = analyseProbe(internalUseProbe('Acme\Blog\Adapter', $trailing, $above));

    expect($analysis->exitCode === 0)->toBe($identifiers === [])
        ->and($analysis->identifiers)->toEqualCanonicalizing($identifiers);
})->with([
    'the identifier at the end of the line, with a reason' => ['// @phpstan-ignore cboxCms.internalUse (no stable API yet)', '', []],
    'the identifier on the line above' => ['', '// @phpstan-ignore cboxCms.internalUse', []],
    '@phpstan-ignore-line' => ['// @phpstan-ignore-line', '', ['cboxCms.internalUse', 'ignore.unmatchedLine']],
    '@phpstan-ignore-next-line' => ['', '// @phpstan-ignore-next-line', ['cboxCms.internalUse', 'ignore.unmatchedLine']],
    '@phpstan-ignore without an identifier' => ['// @phpstan-ignore', '', ['cboxCms.internalUse', 'ignore.parseError']],
    '@phpstan-ignore for another identifier' => ['// @phpstan-ignore staticMethod.internal', '', ['cboxCms.internalUse', 'ignore.unmatchedIdentifier']],
]);

it('reports the ignore comment that hides an internal use outside Boundary and Adapter', function (): void {
    $analysis = analyseProbe(internalUseProbe('Acme\Blog\Domain', '// @phpstan-ignore cboxCms.internalUse'));

    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toBe(['cboxCms.phpstanIgnore']);
});

it('reports an internal use that an ignoreErrors entry of the addon matches', function (string $entry): void {
    $analysis = analyseProbe(internalUseProbe('Acme\Blog\Adapter'), "    reportUnmatchedIgnoredErrors: false\n    ignoreErrors:\n{$entry}");

    expect($analysis->exitCode)->not->toBe(0)
        ->and($analysis->identifiers)->toBe(['cboxCms.internalUse']);
})->with([
    'a message pattern' => ["        - '#internal class#'\n"],
    'a pattern for every message' => ["        - '#.*#'\n"],
    'the identifier' => ["        -\n            identifier: cboxCms.internalUse\n"],
    'a raw message' => ["        -\n            rawMessage: 'Call to method isTestCode() of internal class Cbox\\Cms\\Testkit\\Phpstan\\LayerScope. It is marked #[Internal], and only code in the Cbox\\Cms namespace may use it (GUARDRAILS 2.3). Use a #[Stable] or #[Experimental] contract instead.'\n"],
    'the path of the file' => ["        -\n            message: '#.*#'\n            path: Probe.php\n"],
]);

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
