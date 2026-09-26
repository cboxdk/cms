<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fakes;

use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Override;

/**
 * Scan roots as a test declares them, without files or reflection.
 *
 * Each known directory holds a Discovery: the declarations in it and the problems found in its
 * files. A scan stamps each entry with the package of the root it was found through, as the
 * attribute scanner does, visits the roots sorted by directory and package, scans a directory and
 * package pair once, reports a class that two roots reach with ClassInTwoRoots, and reports a
 * directory it does not know with InvalidScanRoot. Every scan's roots are kept in $scanned.
 * DeclarationScannerBehaviour holds it to AttributeScanner.
 */
final class FakeDeclarationScanner implements DeclarationScanner
{
    /** @var list<ScanRoots> */
    public array $scanned = [];

    /**
     * @param  array<string, Discovery>  $directories  what each directory declares, by absolute path;
     *                                                 the package of its entries is replaced by the root's
     */
    public function __construct(private readonly array $directories = []) {}

    #[Override]
    public function scan(ScanRoots $roots): Discovery
    {
        $this->scanned[] = $roots;

        $actions = [];
        $commands = [];
        $hooks = [];
        $problems = [];

        /** @var array<string, ScanRoot> $unique */
        $unique = [];

        foreach ($roots->roots as $root) {
            if (! isset($this->directories[$root->directory])) {
                $problems[] = new BuildProblem(BuildErrorCode::InvalidScanRoot, sprintf(
                    'The scan root %s of %s is not a readable directory. Fix the path the package\'s service provider returns from scanRoots().',
                    $root->directory,
                    $root->package,
                ));

                continue;
            }

            $unique[$root->directory."\0".$root->package] = $root;
        }

        ksort($unique, SORT_STRING);

        /** @var array<string, ScanRoot> $owners the root each class was first found in, by lower-case class name */
        $owners = [];

        foreach ($unique as $root) {
            $found = $this->directories[$root->directory];
            $package = $root->package;

            foreach ($this->classes($found) as $class) {
                $owner = $owners[strtolower($class)] ?? null;

                if ($owner instanceof ScanRoot && $owner !== $root) {
                    $problems[] = new BuildProblem(BuildErrorCode::ClassInTwoRoots, sprintf(
                        'Class %s is in the scan roots %s (%s) and %s (%s). Give each package its own directory, and declare each directory once.',
                        $class,
                        $owner->directory,
                        $owner->package,
                        $root->directory,
                        $root->package,
                    ));
                }

                $owners[strtolower($class)] ??= $root;
            }

            foreach ($found->actions as $action) {
                if ($owners[strtolower($action->class)] === $root) {
                    $actions[] = new ActionEntry($action->class, $package, $action->surfaces);
                }
            }

            foreach ($found->commands as $command) {
                if ($owners[strtolower($command->class)] === $root) {
                    $commands[] = new CommandEntry($command->name, $command->version, $command->class, $package);
                }
            }

            foreach ($found->hooks as $hook) {
                if ($owners[strtolower($hook->class)] === $root) {
                    $hooks[] = new DiscoveredHook($hook->class, $package, $hook->commandClass, $hook->phase, $hook->priority, $hook->budgetMs);
                }
            }

            array_push($problems, ...$found->problems);
        }

        return new Discovery($actions, $commands, $hooks, $problems);
    }

    /**
     * The declared classes of a directory, each once, in the order they are declared.
     *
     * @return list<string>
     */
    private function classes(Discovery $found): array
    {
        $classes = [
            ...array_map(static fn (ActionEntry $action): string => $action->class, $found->actions),
            ...array_map(static fn (CommandEntry $command): string => $command->class, $found->commands),
            ...array_map(static fn (DiscoveredHook $hook): string => $hook->class, $found->hooks),
        ];

        return array_values(array_unique($classes));
    }
}
