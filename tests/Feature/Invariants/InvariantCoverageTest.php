<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Cbox\Cms\Tests\Support\Invariants\InvariantCoverage;
use Cbox\Cms\Tests\Support\Invariants\InvariantCoverageAudit;
use Cbox\Cms\Tests\Support\Invariants\Issuer;
use Cbox\Cms\Tests\Support\Invariants\M1InvariantMap;
use Cbox\Cms\Tests\Support\Invariants\TestFileReader;
use Cbox\Cms\Tests\Support\Invariants\TestReference;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Docs\Boundary\PhpunitGateSuites;
use Cbox\Cms\Tooling\Docs\Domain\GateSuites;
use Cbox\Cms\Tooling\Docs\Domain\SuiteDirectory;
use Cbox\Cms\Tooling\Docs\Domain\SuiteSelection;

/*
 * PRD 6.5's invariants have tests that try to break them through every issuer (GUARDRAILS 9 and
 * 11). Until the property-based generator exists, tests/Support/Invariants/M1InvariantMap.php
 * maps each invariant M1 touches to the tests that cover it and the issuers they go through, and
 * this file holds the map to the tests of the checkout.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

function invariantCoverageSuites(): GateSuites
{
    return PhpunitGateSuites::read(Codebase::root().'/phpunit.xml');
}

/**
 * @param  list<InvariantCoverage>  $map
 * @return list<string>
 */
function invariantCoverageProblems(array $map): array
{
    return InvariantCoverageAudit::problems($map, M1InvariantMap::TOUCHED, Codebase::root(), invariantCoverageSuites());
}

/**
 * @param  list<Issuer>  $issuers
 */
function coveredTest(string $name, array $issuers = [Issuer::Kernel]): TestReference
{
    return TestReference::of('tests/CoveredTest.php', $name, $issuers);
}

it('maps every invariant M1 touches to tests that exist, run in gate 5 and are not skipped', function (): void {
    Rules::none(
        invariantCoverageProblems(M1InvariantMap::map()),
        'Every invariant of PRD 6.5 that M1 touches needs a test in M1InvariantMap, and every mapped test must exist, run in gate 5 and not be skipped (GUARDRAILS 9, 11):',
    );
});

it('covers the invariants 1, 2, 3, 4, 5, 6, 10, 11, 12, 13, 14, 15, 18, 21, 22, 25, 36 and 37, in order, each once', function (): void {
    $mapped = array_map(static fn (InvariantCoverage $entry): int => $entry->invariant, M1InvariantMap::map());

    expect(M1InvariantMap::TOUCHED)->toBe([1, 2, 3, 4, 5, 6, 10, 11, 12, 13, 14, 15, 18, 21, 22, 25, 36, 37])
        ->and($mapped)->toBe(M1InvariantMap::TOUCHED);
});

it('fails on a planted map entry that names a missing test, and on one in a missing file', function (): void {
    $planted = M1InvariantMap::map();
    $planted[0] = new InvariantCoverage($planted[0]->invariant, $planted[0]->rule, [
        ...$planted[0]->tests,
        TestReference::of('tests/Postgres/WalkingSkeleton/ConcurrentSavesTest.php', 'commits both of two concurrent revises', [Issuer::Kernel]),
        TestReference::of('tests/Postgres/WalkingSkeleton/NoSuchTest.php', 'commits a changeset', [Issuer::Kernel]),
    ]);

    expect(invariantCoverageProblems($planted))->toBe([
        "invariant 1: tests/Postgres/WalkingSkeleton/ConcurrentSavesTest.php::commits both of two concurrent revises: tests/Postgres/WalkingSkeleton/ConcurrentSavesTest.php declares no test named 'commits both of two concurrent revises'",
        'invariant 1: tests/Postgres/WalkingSkeleton/NoSuchTest.php::commits a changeset: the file tests/Postgres/WalkingSkeleton/NoSuchTest.php does not exist',
    ]);
});

it('fails on an invariant of the list without an entry or without a test, and on entries outside the list or mapped twice', function (): void {
    $map = M1InvariantMap::map();
    $withoutTests = new InvariantCoverage($map[1]->invariant, $map[1]->rule, []);
    $planted = [$map[0], $withoutTests, ...array_slice($map, 3), $map[0], new InvariantCoverage(7, 'Withdrawn is sticky.', $map[0]->tests), new InvariantCoverage(39, 'None.', $map[0]->tests)];

    expect(invariantCoverageProblems($planted))->toBe([
        'invariant 1: the map has more than one entry for it',
        'invariant 7: the map covers it, but it is not in the list of invariants to cover',
        'invariant 39: PRD 6.5 has no invariant with this number',
        'invariant 3: the map has no entry for it, so no test covers it',
        'invariant 2: no test covers it',
    ]);
});

it('fails on a mapped test that is skipped, in no suite of gate 5, without an issuer or named twice', function (): void {
    $root = ScratchDirectory::make();
    ScratchDirectory::write($root.'/tests/CoveredTest.php', str_replace('PENDING', 'to'.'do', <<<'PHP'
        <?php

        it('holds', function (): void {
            expect(collect([1, 2])->skip(1)->all())->toBe([1 => 2]);
        });

        it('is skipped by its chain', function (): void {
            expect(true)->toBeTrue();
        })->skip('not yet');

        test('is pending')->PENDING();

        it('skips itself', function (): void {
            $this->markTestSkipped('not yet');
        });

        it('is skipped on CI', function (): void {
            expect(true)->toBeTrue();
        })->skipOnCi();
        PHP));
    ScratchDirectory::write($root.'/other/CoveredTest.php', "<?php\n\nit('holds', function (): void {});\n");
    $suites = new GateSuites([new SuiteSelection('Unit', [new SuiteDirectory('tests')])]);

    $problems = InvariantCoverageAudit::problems([new InvariantCoverage(1, 'Rule.', [
        coveredTest('holds'),
        coveredTest('is skipped by its chain'),
        coveredTest('is pending'),
        coveredTest('skips itself'),
        coveredTest('is skipped on CI'),
        coveredTest('holds'),
        TestReference::of('other/CoveredTest.php', 'holds', [Issuer::Kernel]),
    ]), new InvariantCoverage(2, 'Rule.', [
        coveredTest('holds', []),
        TestReference::of('tests/CoveredTest.php', 'holds', [Issuer::Rest, Issuer::Mcp, Issuer::Rest]),
    ])], [1, 2], $root, $suites);

    expect($problems)->toBe([
        'invariant 1: tests/CoveredTest.php::is skipped by its chain: the test is skipped, or its file may skip it',
        'invariant 1: tests/CoveredTest.php::is pending: the test is skipped, or its file may skip it',
        'invariant 1: tests/CoveredTest.php::skips itself: the test is skipped, or its file may skip it',
        'invariant 1: tests/CoveredTest.php::is skipped on CI: the test is skipped, or its file may skip it',
        'invariant 1: tests/CoveredTest.php::holds: named twice for the invariant',
        'invariant 1: other/CoveredTest.php::holds: other/CoveredTest.php is in no suite of gate 5 (Unit), so the test never runs',
        'invariant 2: tests/CoveredTest.php::holds: names no issuer it tries the invariant through',
        'invariant 2: tests/CoveredTest.php::holds: named twice for the invariant',
    ]);
});

it('reads the tests of a Pest file and a PHPUnit class, and what skips them', function (): void {
    $pest = TestFileReader::read(<<<'PHP'
        <?php

        describe('a group', function (): void {
            it('holds in a group', function (): void {});
        });

        it("holds with \"quotes\"", function (): void {});
        it('holds with \'quotes\'', fn () => null)->with([1]);
        arch('layers: nothing', fn () => null);
        $this->it('is no test');
        PHP);
    $skippedFile = TestFileReader::read(<<<'PHP'
        <?php

        beforeEach(function (): void {
            $this->markTestSkipped('later');
        });

        it('holds', function (): void {});
        PHP);
    $class = TestFileReader::read(<<<'PHP'
        <?php

        final class SomeTest extends TestCase
        {
            private const string NAME = SomeTest::class;

            #[Test]
            #[DataProvider('cases')]
            public function it_holds(): void
            {
                $helper = new readonly class {
                    public function test_in_an_anonymous_class(): void {}
                };
            }

            public function test_holds(): void {}

            #[Test]
            #[RequiresPhpExtension('xdebug')]
            public function it_needs_xdebug(): void {}

            #[Test]
            public function it_skips_itself(): void
            {
                $this->markTestIncomplete();
            }

            public static function cases(): array
            {
                return [];
            }
        }
        PHP);
    $skippedClass = TestFileReader::read(<<<'PHP'
        <?php

        final class SomeTest extends TestCase
        {
            protected function setUp(): void
            {
                $this->markTestSkipped('later');
            }

            public function test_holds(): void {}
        }
        PHP);

    expect($pest->tests)->toBe(['holds in a group', 'holds with "quotes"', "holds with 'quotes'", 'layers: nothing'])
        ->and($pest->skipped)->toBe([])
        ->and($pest->skipsAll)->toBeFalse()
        ->and($skippedFile->skips('holds'))->toBeTrue()
        ->and($class->tests)->toBe(['it_holds', 'test_holds', 'it_needs_xdebug', 'it_skips_itself'])
        ->and($class->skipped)->toBe(['it_needs_xdebug', 'it_skips_itself'])
        ->and($class->skipsAll)->toBeFalse()
        ->and($skippedClass->tests)->toBe(['test_holds'])
        ->and($skippedClass->skips('test_holds'))->toBeTrue();
});

it('has an issuer for every issuer of an envelope, and names the issuers an invariant is not tried through', function (): void {
    $entry = new InvariantCoverage(11, 'Rule.', [
        TestReference::of('tests/CoveredTest.php', 'holds', [Issuer::Rest, Issuer::Kernel]),
        TestReference::of('tests/CoveredTest.php', 'holds too', [Issuer::Seed, Issuer::Cli]),
    ]);

    expect(array_map(static fn (Issuer $issuer): ?IssuingSurface => $issuer->issuingSurface(), Issuer::envelopeIssuers()))->toBe(IssuingSurface::cases())
        ->and(Issuer::Kernel->issuingSurface())->toBeNull()
        ->and($entry->envelopeIssuersMissing())->toBe([Issuer::Inertia, Issuer::Mcp, Issuer::Job, Issuer::Scheduler, Issuer::Subscriber, Issuer::Sidecar, Issuer::Maintenance]);
});

it('refuses a test id that is not a path and a name', function (string $id): void {
    expect(static fn (): TestReference => new TestReference($id, [Issuer::Kernel]))->toThrow(InvalidArgumentException::class, "The test id {$id} is not <path>::<test name>.");
})->with(['no separator' => ['tests/CoveredTest.php'], 'no path' => ['::holds'], 'no name' => ['tests/CoveredTest.php::']]);
