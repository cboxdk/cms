<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Surfaces;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Tests\Support\SurfaceContract\RestProfile;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCase;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCases;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceProfile;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceProfiles;
use Cbox\Cms\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The surface contract tests (GUARDRAILS 2.1, 9: "én per action og overflade"; MILESTONES M1
 * point 6). The data set is built from the registry of the installation, compiled as cms:build
 * compiles it: one test per write action and surface its #[Action] lists, so a new action gets its
 * surface tests without writing them. Each sends a field error (in the document and in the
 * fields), a version conflict, a dry run and a receipt that is committed but did not reach its
 * wait level through the surface's own transport, with the smallest document the command's JSON
 * Schema accepts, over the real RunExposedCommand and command pipeline with fakes below them
 * (ContractKernel), and checks the answer against the surface's profile. A surface without a
 * profile fails its tests.
 *
 * PHPUnit resolves the data set before any test runs, so it compiles the registry in an
 * application booted for it (SurfaceContractCases::booted()).
 */
final class SurfaceContractTest extends TestCase
{
    /** The commands of M1 point 3 that the kernel exposes on every surface. */
    private const array EXPOSED = ['entry.create', 'entry.publish', 'entry.revise', 'entry.unpublish', 'placement.create', 'placement.set_window', 'variant.release'];

    /** The command of M1 point 3 that is exposed on no surface: its surfaces come with B1 and B6. */
    private const string UNEXPOSED = 'actor.deactivate';

    /**
     * @return array<string, array{SurfaceContractCase}>
     */
    public static function surfaceContracts(): array
    {
        return SurfaceContractCases::of(SurfaceContractCases::booted(), SurfaceProfiles::all());
    }

    #[Test]
    #[DataProvider('surfaceContracts')]
    public function it_keeps_the_surface_contract_of_the_action_on_the_surface(SurfaceContractCase $case): void
    {
        $case->verify($this);
    }

    #[Test]
    public function it_has_one_case_per_surface_for_each_exposed_m1_command_and_none_for_the_command_exposed_on_no_surface(): void
    {
        $registry = SurfaceContractCases::installation(app());
        $expected = [];

        foreach (self::EXPOSED as $command) {
            foreach (Surface::cases() as $surface) {
                $expected[] = sprintf('%s v1 on %s', $command, $surface->value);
            }
        }

        $unexposed = array_values(array_filter(
            $registry->actions,
            static fn (ActionEntry $action): bool => $action->kind === ActionKind::Write && $action->command->value === self::UNEXPOSED,
        ));

        self::assertSame($expected, array_keys(SurfaceContractCases::of($registry, SurfaceProfiles::all())));
        self::assertCount(1, $unexposed);
        self::assertSame([], $unexposed[0]->surfaces);
    }

    #[Test]
    public function it_has_a_profile_for_every_surface(): void
    {
        $profiles = SurfaceProfiles::all();

        self::assertSame(Surface::cases(), array_map(static fn (Surface $surface): ?Surface => $profiles->for($surface)?->surface(), Surface::cases()));
    }

    #[Test]
    public function it_fails_the_test_of_an_action_on_a_surface_that_has_no_profile(): void
    {
        $cases = SurfaceContractCases::of(SurfaceContractCases::installation(app()), new SurfaceProfiles(new RestProfile));
        $unprofiled = $cases['entry.create v1 on inertia'][0];

        self::assertNull($unprofiled->profile);
        self::assertInstanceOf(SurfaceProfile::class, $cases['entry.create v1 on rest'][0]->profile);

        try {
            $unprofiled->verify($this);
        } catch (AssertionFailedError $failed) {
            self::assertStringContainsString('exposes entry.create version 1 on the surface inertia, which has no profile in', $failed->getMessage());

            return;
        }

        self::fail('The test of an action on a surface without a profile passed.');
    }
}
