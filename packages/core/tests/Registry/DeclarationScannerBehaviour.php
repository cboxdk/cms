<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every DeclarationScanner does (PRD 13.2), run against AttributeScanner on the fixture
 * directories and against FakeDeclarationScanner, so the fake the registry's action tests use
 * cannot drift from the scanner (GUARDRAILS 9).
 */
trait DeclarationScannerBehaviour
{
    /** A directory that does not exist. */
    protected const string MISSING_DIRECTORY = '/nonexistent/cbox-cms-scan-root';

    abstract protected function declarationScanner(): DeclarationScanner;

    /**
     * An absolute directory that declares what RegistryFixtures::validDiscovery() lists.
     */
    abstract protected function declaringDirectory(): string;

    /**
     * An absolute directory that declares nothing.
     */
    abstract protected function quietDirectory(): string;

    #[Test]
    public function no_roots_find_nothing(): void
    {
        Assert::assertEquals(new Discovery([], [], [], []), $this->declarationScanner()->scan(new ScanRoots));
    }

    #[Test]
    public function it_finds_the_declarations_of_a_root_under_the_roots_package(): void
    {
        $found = $this->declarationScanner()->scan(new ScanRoots(new ScanRoot('acme/notes', $this->declaringDirectory())));

        Assert::assertEquals(RegistryFixtures::validDiscovery('acme/notes'), $found);
    }

    #[Test]
    public function a_directory_without_declarations_finds_nothing(): void
    {
        Assert::assertEquals(new Discovery([], [], [], []), $this->declarationScanner()->scan(new ScanRoots(new ScanRoot('acme/quiet', $this->quietDirectory()))));
    }

    #[Test]
    public function a_root_that_is_not_a_directory_is_a_problem_and_the_other_roots_are_still_scanned(): void
    {
        $found = $this->declarationScanner()->scan(new ScanRoots(
            new ScanRoot('acme/missing', self::MISSING_DIRECTORY),
            new ScanRoot('acme/notes', $this->declaringDirectory()),
        ));

        Assert::assertSame([BuildErrorCode::InvalidScanRoot], array_map(static fn (BuildProblem $problem): BuildErrorCode => $problem->code, $found->problems));
        Assert::assertSame(
            'The scan root '.self::MISSING_DIRECTORY.' of acme/missing is not a readable directory. Fix the path the package\'s service provider returns from scanRoots().',
            $found->problems[0]->message,
        );
        Assert::assertEquals(RegistryFixtures::validDiscovery('acme/notes')->actions, $found->actions);
        Assert::assertEquals(RegistryFixtures::validDiscovery('acme/notes')->commands, $found->commands);
        Assert::assertEquals(RegistryFixtures::validDiscovery('acme/notes')->hooks, $found->hooks);
    }

    #[Test]
    public function the_same_directory_and_package_twice_is_scanned_once(): void
    {
        $root = new ScanRoot('acme/notes', $this->declaringDirectory());

        Assert::assertEquals(RegistryFixtures::validDiscovery('acme/notes'), $this->declarationScanner()->scan(new ScanRoots($root, $root)));
    }

    #[Test]
    public function the_order_of_the_roots_does_not_change_what_is_found(): void
    {
        $notes = new ScanRoot('acme/notes', $this->declaringDirectory());
        $quiet = new ScanRoot('acme/quiet', $this->quietDirectory());
        $scanner = $this->declarationScanner();

        Assert::assertEquals($scanner->scan(new ScanRoots($notes, $quiet)), $scanner->scan(new ScanRoots($quiet, $notes)));
    }

    #[Test]
    public function a_directory_that_two_packages_declare_is_a_problem_for_every_class_in_it(): void
    {
        $found = $this->declarationScanner()->scan(new ScanRoots(
            new ScanRoot('acme/zeta', $this->declaringDirectory()),
            new ScanRoot('acme/alpha', $this->declaringDirectory()),
        ));
        $first = RegistryFixtures::validDiscovery('acme/alpha');

        Assert::assertNotSame([], $found->problems);

        foreach ($found->problems as $problem) {
            Assert::assertSame(BuildErrorCode::ClassInTwoRoots, $problem->code);
            Assert::assertStringContainsString('(acme/alpha) and ', $problem->message);
            Assert::assertStringContainsString('(acme/zeta). Give each package its own directory', $problem->message);
        }

        Assert::assertEquals([$first->actions, $first->commands, $first->hooks], [$found->actions, $found->commands, $found->hooks]);
    }
}
