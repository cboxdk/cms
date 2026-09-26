<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Boundary\ProviderScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;

/**
 * `cms:build`: compiles the registries of actions, commands, hooks, subscribers, slots and schema
 * contributions to bootstrap/cache/cms/ (PRD 13.2, GUARDRAILS 7.1), from the scan roots the
 * service providers declare. Composer runs it after every dump-autoload.
 *
 * Exit codes: 0 written, 65 the declarations are invalid and nothing was written (each problem is
 * printed with its code), 73 a cache file could not be written.
 */
#[Internal]
#[Description('Compile the registries of actions, commands, hooks, subscribers, slots and schema to bootstrap/cache/cms')]
#[Signature('cms:build')]
final class BuildCommand extends Command
{
    /** EX_DATAERR from sysexits.h. */
    public const int EXIT_INVALID_DECLARATIONS = 65;

    /** EX_CANTCREAT from sysexits.h. */
    public const int EXIT_UNWRITABLE = 73;

    public function handle(BuildRegistry $build, Application $app): int
    {
        try {
            $registry = $build->build(ProviderScanRoots::of($app));
        } catch (RegistryBuildFailed $failed) {
            foreach ($failed->problems as $problem) {
                $this->error($problem->describe());
            }

            $this->error('The registry was not built, and the cache was left as it was.');

            return self::EXIT_INVALID_DECLARATIONS;
        } catch (RegistryCacheUnwritable $unwritable) {
            $this->error($unwritable->getMessage());

            return self::EXIT_UNWRITABLE;
        }

        foreach (RegistryName::cases() as $name) {
            $this->line(sprintf('%s: %d', $name->value, $registry->count($name)));
        }

        $this->info(sprintf('Registry written to %s.', $build->location()));

        return self::SUCCESS;
    }
}
