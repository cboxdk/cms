<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Surfaces;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbeAction;
use Cbox\Cms\Tests\Support\SurfaceContract\McpQueryProfile;
use Cbox\Cms\Tests\Support\SurfaceContract\QueryContractCase;
use Cbox\Cms\Tests\Support\SurfaceContract\QuerySurfaceProfiles;
use Cbox\Cms\Tests\Support\SurfaceContract\RestProfile;
use Cbox\Cms\Tests\Support\SurfaceContract\RestQueryProfile;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCase;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCases;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceProfile;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceProfiles;
use Cbox\Cms\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * The surface contract tests (GUARDRAILS 2.1, 9: "én per action og overflade"; MILESTONES M1
 * point 6). The data set is built from the registry of the installation, compiled as cms:build
 * compiles it: one test per action and surface its #[Action] lists, so a new action gets its
 * surface tests without writing them. A write's test sends a field error (in the document and in
 * the fields), a version conflict, a dry run and a receipt that is committed but did not reach its
 * wait level through the surface's own transport, with the smallest document the command's JSON
 * Schema accepts, over the real RunExposedCommand and command pipeline with fakes below them
 * (ContractKernel), and checks the answer against the surface's profile. A query's test sends a
 * document its codec refuses, a principal the authorizer refuses and a read the kernel answers,
 * over the real QueryPipeline with fakes below it (QueryContractKernel), and checks the problem or
 * the result its result codec writes against the surface's query profile. A surface without a
 * profile, and a query without a QueryCodec, fail their tests.
 *
 * PHPUnit resolves the data set before any test runs, so it compiles the registry in an
 * application booted for it (SurfaceContractCases::booted()).
 */
final class SurfaceContractTest extends TestCase
{
    /** The commands of M1 point 3 that the kernel exposes on every surface. */
    private const array EXPOSED = ['entry.create', 'entry.publish', 'entry.revise', 'entry.unpublish', 'placement.create', 'placement.set_window', 'variant.release'];

    /**
     * The kernel's commands exposed on some surfaces, by name, with those surfaces (B1 point 4):
     * actor.activate, grant.assign, grant.revoke, role.create and role.set_permissions on every
     * surface but MCP, and actor.register on the CLI alone.
     */
    private const array SOME_SURFACES = [
        'actor.activate' => ['rest', 'inertia', 'cli'],
        'actor.register' => ['cli'],
        'grant.assign' => ['rest', 'inertia', 'cli'],
        'grant.revoke' => ['rest', 'inertia', 'cli'],
        'role.create' => ['rest', 'inertia', 'cli'],
        'role.set_permissions' => ['rest', 'inertia', 'cli'],
    ];

    /** The command of M1 point 3 that is exposed on no surface: its surfaces come with B1 and B6. */
    private const string UNEXPOSED = 'actor.deactivate';

    /** The kernel's query, exposed on no surface, which the query cases' own tests plant on some. */
    private const string KERNEL_QUERY = 'path.resolve';

    /**
     * @return array<string, array{SurfaceContractCase|QueryContractCase}>
     */
    public static function surfaceContracts(): array
    {
        return SurfaceContractCases::of(SurfaceContractCases::booted(), SurfaceProfiles::all());
    }

    #[Test]
    #[DataProvider('surfaceContracts')]
    public function it_keeps_the_surface_contract_of_the_action_on_the_surface(SurfaceContractCase|QueryContractCase $case): void
    {
        $case->verify($this);
    }

    #[Test]
    public function it_has_one_case_per_surface_for_each_exposed_m1_command_and_none_for_the_command_exposed_on_no_surface(): void
    {
        $registry = SurfaceContractCases::installation(app());
        $expected = [];
        $commands = [...self::SOME_SURFACES, ...array_fill_keys(self::EXPOSED, array_map(static fn (Surface $surface): string => $surface->value, Surface::cases()))];
        ksort($commands);

        foreach ($commands as $command => $surfaces) {
            foreach ($surfaces as $surface) {
                $expected[] = sprintf('%s v1 on %s', $command, $surface);
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

    #[Test]
    public function it_builds_a_query_case_for_each_surface_a_query_action_lists_and_keeps_the_contract_of_the_kernel_query_on_rest_and_mcp(): void
    {
        $installation = SurfaceContractCases::installation(app());
        $registry = $this->planted($installation, $this->exposed($this->kernelQuery($installation), [Surface::Rest, Surface::Mcp]));
        $cases = SurfaceContractCases::of($registry, SurfaceProfiles::all());
        $queries = array_values(array_filter(array_map(static fn (array $case): SurfaceContractCase|QueryContractCase => $case[0], $cases), static fn (SurfaceContractCase|QueryContractCase $case): bool => $case instanceof QueryContractCase));

        self::assertSame(['path.resolve v1 on rest', 'path.resolve v1 on mcp'], array_map(static fn (QueryContractCase $case): string => $case->name(), $queries));
        self::assertSame([], array_values(array_filter(array_keys(SurfaceContractCases::of($installation, SurfaceProfiles::all())), static fn (string $name): bool => str_starts_with($name, self::KERNEL_QUERY.' '))));

        foreach ($queries as $case) {
            $case->verify($this);
        }
    }

    #[Test]
    public function it_fails_the_test_of_a_query_exposed_on_rest_without_a_codec(): void
    {
        $planted = new ActionEntry(ReadProbeAction::class, 'acme/probe', ActionKind::Query, new CommandName('probe.read'), 2, ReadProbe::class, [Surface::Rest]);
        $case = SurfaceContractCases::of($this->planted(SurfaceContractCases::installation(app()), $planted), SurfaceProfiles::all())['probe.read v2 on rest'][0];

        self::assertInstanceOf(QueryContractCase::class, $case);
        self::assertInstanceOf(RestQueryProfile::class, $case->profile);
        $this->assertCaseFails($case, sprintf('The installation has no query codec of probe.read version 2, which %s exposes on rest.', ReadProbeAction::class));
    }

    #[Test]
    public function it_fails_the_test_of_a_query_on_a_surface_without_a_query_profile(): void
    {
        $installation = SurfaceContractCases::installation(app());
        $registry = $this->planted($installation, $this->exposed($this->kernelQuery($installation), [Surface::Inertia, Surface::Mcp, Surface::Cli]));
        $cases = SurfaceContractCases::of($registry, SurfaceProfiles::all(), new QuerySurfaceProfiles(new McpQueryProfile));

        self::assertInstanceOf(McpQueryProfile::class, $cases['path.resolve v1 on mcp'][0]->profile);

        foreach (['inertia', 'cli'] as $surface) {
            $case = $cases['path.resolve v1 on '.$surface][0];
            self::assertInstanceOf(QueryContractCase::class, $case);
            self::assertNull($case->profile);
            $this->assertCaseFails($case, sprintf('exposes the query path.resolve version 1 on the surface %s, which has no query profile in', $surface));
        }
    }

    #[Test]
    public function it_has_a_query_profile_for_rest_and_mcp_and_none_for_the_surfaces_that_serve_no_reads_yet(): void
    {
        $profiles = QuerySurfaceProfiles::all();

        self::assertSame(
            [Surface::Rest, null, Surface::Mcp, null],
            array_map(static fn (Surface $surface): ?Surface => $profiles->for($surface)?->surface(), Surface::cases()),
        );
    }

    /**
     * The registry with each action given in place of the action of its query or command, and its
     * REST route when it lists REST, as cms:build would compile it.
     */
    private function planted(CompiledRegistry $registry, ActionEntry ...$planted): CompiledRegistry
    {
        $names = array_map(static fn (ActionEntry $action): string => $action->command->value.'@'.$action->commandVersion, $planted);
        $kept = array_values(array_filter($registry->actions, static fn (ActionEntry $action): bool => ! in_array($action->command->value.'@'.$action->commandVersion, $names, true)));
        $routes = array_values(array_filter(array_map(RestRoute::of(...), $planted)));

        return new CompiledRegistry($registry->commands, $registry->hooks, [...$kept, ...array_values($planted)], $registry->subscribers, $registry->schema, [...$registry->rest, ...$routes]);
    }

    private function kernelQuery(CompiledRegistry $registry): ActionEntry
    {
        foreach ($registry->actions as $action) {
            if ($action->kind === ActionKind::Query && $action->command->value === self::KERNEL_QUERY) {
                self::assertSame([], $action->surfaces, 'path.resolve is exposed on no surface');

                return $action;
            }
        }

        self::fail('The installation has no query action of path.resolve.');
    }

    /**
     * @param  list<Surface>  $surfaces
     */
    private function exposed(ActionEntry $action, array $surfaces): ActionEntry
    {
        return new ActionEntry($action->class, $action->package, $action->kind, $action->command, $action->commandVersion, $action->commandClass, $surfaces);
    }

    private function assertCaseFails(QueryContractCase $case, string $message): void
    {
        try {
            $case->verify($this);
        } catch (AssertionFailedError $failed) {
            self::assertNotInstanceOf(ExpectationFailedException::class, $failed, $failed->getMessage());
            self::assertStringContainsString($message, $failed->getMessage());

            return;
        }

        self::fail(sprintf('The query case %s passed.', $case->name()));
    }
}
