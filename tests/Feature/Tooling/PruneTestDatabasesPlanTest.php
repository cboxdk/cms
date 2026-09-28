<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\TestDatabase\Boundary\FilesystemCheckouts;
use Cbox\Cms\Tooling\TestDatabase\Domain\Checkouts;
use Cbox\Cms\Tooling\TestDatabase\Domain\ListedDatabase;
use Cbox\Cms\Tooling\TestDatabase\Domain\PruneContext;
use Cbox\Cms\Tooling\TestDatabase\Domain\PruneDecision;
use Cbox\Cms\Tooling\TestDatabase\Domain\PrunePlan;
use Cbox\Cms\Tooling\TestDatabase\Domain\PruneVerdict;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/*
 * The verdicts of `composer test-db:prune` (PrunePlan): it drops only a database named
 * <configured>_<12 hex>, or a parallel worker's named <configured>_<12 hex>_w<n>, whose testkit
 * comment names this host and a checkout that is gone or now derives another name, and keeps
 * everything else. tests/Postgres/PruneTestDatabasesTest.php runs
 * the script against the real server.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * Checkouts that answer from a fixed map of path to derived name; a path not in it is gone.
 *
 * @param  array<string, string>  $derived
 */
function pruneCheckouts(array $derived): Checkouts
{
    return new readonly class($derived) implements Checkouts
    {
        /**
         * @param  array<string, string>  $derived
         */
        public function __construct(private array $derived) {}

        public function derivedName(string $base, string $checkout): ?string
        {
            return $this->derived[$checkout] ?? null;
        }
    };
}

function pruneComment(string $checkout, string $host = 'dev-1'): string
{
    return new TestDatabaseComment($checkout, $host)->encode();
}

/**
 * @return list<string>
 */
function pruneLines(PrunePlan $plan): array
{
    return array_map(static fn (PruneDecision $decision): string => $decision->line(), $plan->decisions);
}

it('drops only the databases of gone or renamed checkouts on this host, and keeps the rest with a reason', function (): void {
    $context = new PruneContext('cms_test', 'cms_test_000000000001', 'dev-1');
    $databases = [
        new ListedDatabase('cms', null),
        new ListedDatabase('cms_test', null),
        new ListedDatabase('cms_test_000000000001', pruneComment('/src/main')),
        new ListedDatabase('cms_test_00000000000a', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_00000000000b', pruneComment('/src/renamed')),
        new ListedDatabase('cms_test_00000000000c', pruneComment('/src/live')),
        new ListedDatabase('cms_test_00000000000d', pruneComment('/src/gone', 'dev-2')),
        new ListedDatabase('cms_test_00000000000e', null),
        new ListedDatabase('cms_test_00000000000f', ''),
        new ListedDatabase('cms_test_0000000000a0', 'made by hand'),
        new ListedDatabase('cms_test_0000000000a1', '{"checkout":"/src/gone"}'),
        new ListedDatabase('cms_test_0000000000A2', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_00000000000', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_0000000000000', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_x_000000000000', pruneComment('/src/gone')),
        new ListedDatabase('other_000000000000', pruneComment('/src/gone')),
        new ListedDatabase('postgres', 'default administrative connection database'),
    ];

    $plan = PrunePlan::for($databases, $context, pruneCheckouts([
        '/src/main' => 'cms_test_000000000001',
        '/src/renamed' => 'cms_test_0000000000ff',
        '/src/live' => 'cms_test_00000000000c',
    ]));

    expect(pruneLines($plan))->toBe([
        'keep cms_test: the configured database, which the owner role connects to.',
        'keep cms_test_000000000001: the test database of this checkout.',
        'drop cms_test_00000000000a: its checkout /src/gone on this host no longer exists.',
        'drop cms_test_00000000000b: its checkout /src/renamed on this host now has the test database cms_test_0000000000ff.',
        'keep cms_test_00000000000c: its checkout /src/live exists on this host.',
        'keep cms_test_00000000000d: its checkout /src/gone is on the host dev-2, not on this host dev-1.',
        'keep cms_test_00000000000e: it has no comment, so the testkit did not provision it or cannot tell its checkout.',
        'keep cms_test_00000000000f: it has no comment, so the testkit did not provision it or cannot tell its checkout.',
        "keep cms_test_0000000000a0: its comment is not the testkit's (The test database comment is not valid JSON: Syntax error).",
        "keep cms_test_0000000000a1: its comment is not the testkit's (The test database comment has no checkout and host).",
    ])
        ->and($plan->drops())->toBe(['cms_test_00000000000a', 'cms_test_00000000000b'])
        ->and(array_map(static fn (PruneDecision $decision): PruneVerdict => $decision->verdict, $plan->decisions))
        ->toBe([PruneVerdict::Keep, PruneVerdict::Keep, PruneVerdict::Drop, PruneVerdict::Drop, PruneVerdict::Keep, PruneVerdict::Keep, PruneVerdict::Keep, PruneVerdict::Keep, PruneVerdict::Keep, PruneVerdict::Keep]);
});

it('plans the databases of parallel workers by the rules of their checkout, and keeps this checkout\'s workers', function (): void {
    $context = new PruneContext('cms_test', 'cms_test_000000000001', 'dev-1');
    $databases = [
        new ListedDatabase('cms_test_000000000001_w1', pruneComment('/src/main')),
        new ListedDatabase('cms_test_000000000001_w12', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_00000000000a_w1', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_00000000000b_w2', pruneComment('/src/renamed')),
        new ListedDatabase('cms_test_00000000000c_w3', pruneComment('/src/live')),
        new ListedDatabase('cms_test_00000000000d_w1', pruneComment('/src/gone', 'dev-2')),
        new ListedDatabase('cms_test_00000000000e_w1', null),
        new ListedDatabase('cms_test_00000000000f_w1', 'made by hand'),
        new ListedDatabase('cms_test_00000000000a_w0', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_00000000000a_w01', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_00000000000a_w', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_00000000000a_wx', pruneComment('/src/gone')),
        new ListedDatabase('cms_test_00000000000a_w1_w2', pruneComment('/src/gone')),
    ];

    $plan = PrunePlan::for($databases, $context, pruneCheckouts([
        '/src/main' => 'cms_test_000000000001',
        '/src/renamed' => 'cms_test_0000000000ff',
        '/src/live' => 'cms_test_00000000000c',
    ]));

    expect(pruneLines($plan))->toBe([
        'keep cms_test_000000000001_w1: the test database of a parallel worker of this checkout.',
        'keep cms_test_000000000001_w12: the test database of a parallel worker of this checkout.',
        'drop cms_test_00000000000a_w1: its checkout /src/gone on this host no longer exists.',
        'drop cms_test_00000000000b_w2: its checkout /src/renamed on this host now has the test database cms_test_0000000000ff.',
        'keep cms_test_00000000000c_w3: its checkout /src/live exists on this host.',
        'keep cms_test_00000000000d_w1: its checkout /src/gone is on the host dev-2, not on this host dev-1.',
        'keep cms_test_00000000000e_w1: it has no comment, so the testkit did not provision it or cannot tell its checkout.',
        "keep cms_test_00000000000f_w1: its comment is not the testkit's (The test database comment is not valid JSON: Syntax error).",
    ])
        ->and($plan->drops())->toBe(['cms_test_00000000000a_w1', 'cms_test_00000000000b_w2'])
        ->and(PrunePlan::checkoutDatabase('cms_test', 'cms_test_0123456789ab_w7'))->toBe('cms_test_0123456789ab')
        ->and(PrunePlan::checkoutDatabase('cms_test', 'cms_test_0123456789ab'))->toBe('cms_test_0123456789ab')
        ->and(PrunePlan::checkoutDatabase('cms_test', 'cms_test_0123456789ab_w7x'))->toBeNull()
        ->and(PrunePlan::checkoutDatabase('cms.test', 'cmsxtest_0123456789ab_w1'))->toBeNull();
});

it('plans the worker databases the testkit names for a checkout on the filesystem: kept while it exists, dropped once it is gone', function (): void {
    $checkout = ScratchDirectory::make('cbox-cms-prune-checkout-');
    $workers = [TestDatabaseName::for('cms_test', $checkout, 1), TestDatabaseName::for('cms_test', $checkout, 2)];
    $listed = array_map(static fn (string $name): ListedDatabase => new ListedDatabase($name, pruneComment($checkout)), $workers);
    $context = new PruneContext('cms_test', 'cms_test_000000000001', 'dev-1');

    $kept = PrunePlan::for($listed, $context, new FilesystemCheckouts);
    rmdir($checkout);
    $dropped = PrunePlan::for($listed, $context, new FilesystemCheckouts);

    expect($kept->drops())->toBe([])
        ->and(pruneLines($kept))->toBe(array_map(static fn (string $name): string => "keep {$name}: its checkout {$checkout} exists on this host.", $workers))
        ->and($dropped->drops())->toBe($workers)
        ->and(pruneLines($dropped))->toBe(array_map(static fn (string $name): string => "drop {$name}: its checkout {$checkout} on this host no longer exists.", $workers));
});

it('keeps the database of this checkout even when its comment names a gone checkout', function (): void {
    $plan = PrunePlan::for(
        [new ListedDatabase('cms_test_000000000001', pruneComment('/src/gone'))],
        new PruneContext('cms_test', 'cms_test_000000000001', 'dev-1'),
        pruneCheckouts([]),
    );

    expect($plan->drops())->toBe([])
        ->and(pruneLines($plan))->toBe(['keep cms_test_000000000001: the test database of this checkout.']);
});

it('matches the configured database literally, not as a pattern', function (): void {
    expect(PrunePlan::isTestDatabaseName('cms.test', 'cms.test_0123456789ab'))->toBeTrue()
        ->and(PrunePlan::isTestDatabaseName('cms.test', 'cmsxtest_0123456789ab'))->toBeFalse()
        ->and(PrunePlan::isTestDatabaseName('cms_test', "cms_test_0123456789ab\n"))->toBeFalse()
        ->and(PrunePlan::isTestDatabaseName('cms_test', 'cms_test_0123456789abc'))->toBeFalse();
});

it('refuses a context without a configured database, a host or a current name of the right form', function (string $base, string $current, string $host, string $message): void {
    expect(static fn (): PruneContext => new PruneContext($base, $current, $host))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no configured database' => ['', 'cms_test_0123456789ab', 'dev-1', 'needs the configured database and the host name'],
    'no host' => ['cms_test', 'cms_test_0123456789ab', '', 'needs the configured database and the host name'],
    'another base' => ['cms_test', 'cms_0123456789ab', 'dev-1', 'The test database of this checkout, cms_0123456789ab, is not named cms_test_<12 hex digits>.'],
]);

it('derives the name of a checkout on the filesystem from its real path, and none for a path that is gone', function (): void {
    $checkout = ScratchDirectory::make('cbox-cms-prune-checkout-');
    $link = ScratchDirectory::make('cbox-cms-prune-link-').'/checkout';
    symlink($checkout, $link);
    $checkouts = new FilesystemCheckouts;

    expect($checkouts->derivedName('cms_test', $checkout))->toBe(TestDatabaseName::for('cms_test', $checkout))
        ->and($checkouts->derivedName('cms_test', $link))->toBe(TestDatabaseName::for('cms_test', $checkout))
        ->and($checkouts->derivedName('cms_test', $checkout.'/missing'))->toBeNull()
        ->and($checkouts->derivedName('cms_test', ScratchDirectory::write($checkout.'/file')))->toBeNull();
});

it('drops the database of a checkout path that is now a link to another checkout', function (): void {
    $moved = ScratchDirectory::make('cbox-cms-prune-moved-');
    $path = ScratchDirectory::make('cbox-cms-prune-path-').'/checkout';
    $original = TestDatabaseName::for('cms_test', ScratchDirectory::make('cbox-cms-prune-original-'));
    symlink($moved, $path);

    $plan = PrunePlan::for(
        [new ListedDatabase($original, pruneComment($path))],
        new PruneContext('cms_test', 'cms_test_000000000001', 'dev-1'),
        new FilesystemCheckouts,
    );

    expect(pruneLines($plan))->toBe([
        "drop {$original}: its checkout {$path} on this host now has the test database ".TestDatabaseName::for('cms_test', $moved).'.',
    ]);
});

/**
 * @param  list<string>  $arguments
 */
function pruneUsage(array $arguments): Process
{
    $process = new Process([PHP_BINARY, 'tools/bin/prune-test-databases.php', ...$arguments], Phpstan::root());
    $process->run();

    return $process;
}

it('exits 2 from the prune script with an argument other than --dry-run', function (array $arguments): void {
    $process = pruneUsage(array_values(array_filter($arguments, is_string(...))));

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toBe("Usage: php tools/bin/prune-test-databases.php [--dry-run]\n");
})->with([
    'an unknown option' => [['--force']],
    'a path' => [['/tmp']],
    'dry run twice' => [['--dry-run', '--dry-run']],
]);
