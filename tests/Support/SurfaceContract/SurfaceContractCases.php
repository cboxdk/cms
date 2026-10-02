<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Core\Registry\Boundary\ProviderAddonManifests;
use Cbox\Cms\Core\Registry\Boundary\ProviderScanRoots;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;

/**
 * The dataset of the surface contract tests (GUARDRAILS 9: one per action and surface): a
 * SurfaceContractCase for every surface each write action of the registry lists, and a
 * QueryContractCase for every surface each query action lists, so a new action gets its surface
 * tests from its #[Action] alone.
 */
final readonly class SurfaceContractCases
{
    /**
     * The cases of the registry with the profiles given, by name, in the order of the registry's
     * actions and of the surfaces each lists: the writes' with the surface profiles, the queries'
     * with the query profiles, QuerySurfaceProfiles::all() unless others are given.
     *
     * @return array<string, array{SurfaceContractCase|QueryContractCase}>
     */
    public static function of(CompiledRegistry $registry, SurfaceProfiles $profiles, ?QuerySurfaceProfiles $queries = null): array
    {
        $queries ??= QuerySurfaceProfiles::all();
        $cases = [];

        foreach ($registry->actions as $action) {
            foreach ($action->surfaces as $surface) {
                $case = $action->kind === ActionKind::Write
                    ? new SurfaceContractCase($registry, $action, $surface, $profiles->for($surface))
                    : new QueryContractCase($registry, $action, $surface, $queries->for($surface));
                $cases[$case->name()] = [$case];
            }
        }

        return $cases;
    }

    /**
     * The registry of the installation, compiled as installation() compiles it, in an application
     * of the workbench booted for it, because PHPUnit resolves a dataset before any test sets up
     * its application. The application is flushed afterwards, and the error and exception
     * handlers its bootstrap set are taken off again, so the first test finds them as PHPUnit left
     * them. Testbench keeps the traits of the test case it saw first in one static cache for every
     * test case class, which it fills before a class's first test and clears after its last; the
     * booted case clears it again, or a test class resolved after it, such as a Postgres test that
     * uses RealPostgres, would run without the setUp and tearDown of its own traits.
     */
    public static function booted(): CompiledRegistry
    {
        $errors = get_error_handler();
        $exceptions = get_exception_handler();
        $booted = new class('surface contract dataset') extends TestCase {};
        $app = $booted->createApplication();

        try {
            return self::installation($app);
        } finally {
            $app->flush();
            $booted::tearDownAfterClassUsingPHPUnit();
            Facade::clearResolvedInstances();

            while (get_error_handler() !== $errors && get_error_handler() !== null) {
                restore_error_handler();
            }

            while (get_exception_handler() !== $exceptions && get_exception_handler() !== null) {
                restore_exception_handler();
            }
        }
    }

    /**
     * The registry of the installation, compiled as cms:build compiles it from the scan roots and
     * addon manifests of the application's service providers, not read from a cache that may be
     * stale.
     */
    public static function installation(Application $app): CompiledRegistry
    {
        return $app->make(RegistryCompiler::class)->compile(
            $app->make(DeclarationScanner::class)->scan(ProviderScanRoots::of($app)),
            ProviderAddonManifests::of($app),
        );
    }
}
