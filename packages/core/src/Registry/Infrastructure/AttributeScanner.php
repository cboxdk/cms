<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Finds #[Action], #[Command] and #[Hook] in the scan roots with reflection, at build time only
 * (GUARDRAILS 2.2).
 *
 * Every .php file below a root is read for the classes it declares, and each class is loaded
 * through the autoloader, as the application loads it at run time. Roots and files are visited in
 * sorted order, and a directory given twice for the same package is scanned once.
 */
#[Internal]
final readonly class AttributeScanner implements DeclarationScanner
{
    public function scan(ScanRoots $roots): Discovery
    {
        $actions = [];
        $commands = [];
        $hooks = [];
        $problems = [];

        /** @var array<string, ResolvedRoot> $owners the root each class was first found in, by lower-case class name */
        $owners = [];

        foreach ($this->uniqueRoots($roots->roots, $problems) as $resolved) {
            $root = $resolved->root;

            foreach ($this->phpFiles($resolved->directory) as $file) {
                $source = $this->source($file);

                if ($source === null) {
                    $problems[] = new BuildProblem(BuildErrorCode::ClassNotLoadable, sprintf('Could not read %s in the scan root of %s.', $file->getPathname(), $root->package));

                    continue;
                }

                foreach (DeclaredClasses::in($source) as $class) {
                    $reflection = $this->load($class, $file->getPathname(), $root, $problems);

                    if (! $reflection instanceof ReflectionClass) {
                        continue;
                    }

                    $owner = $owners[strtolower($reflection->getName())] ?? null;

                    if ($owner instanceof ResolvedRoot) {
                        if ($owner !== $resolved) {
                            $problems[] = new BuildProblem(BuildErrorCode::ClassInTwoRoots, sprintf(
                                'Class %s is in the scan roots %s and %s. Give each package its own directory, and declare each directory once.',
                                $reflection->getName(),
                                $owner->describe(),
                                $resolved->describe(),
                            ));
                        }

                        continue;
                    }

                    $owners[strtolower($reflection->getName())] = $resolved;

                    $this->read($reflection, $root, $actions, $commands, $hooks, $problems);
                }
            }
        }

        return new Discovery($actions, $commands, $hooks, $problems);
    }

    /**
     * The roots as real directories, sorted, each directory and package pair once.
     *
     * @param  list<ScanRoot>  $roots
     * @param  list<BuildProblem>  $problems
     * @return list<ResolvedRoot>
     */
    private function uniqueRoots(array $roots, array &$problems): array
    {
        $unique = [];

        foreach ($roots as $root) {
            $directory = realpath($root->directory);

            if ($directory === false || ! is_dir($directory) || ! is_readable($directory)) {
                $problems[] = new BuildProblem(BuildErrorCode::InvalidScanRoot, sprintf(
                    'The scan root %s of %s is not a readable directory. Fix the path the package\'s service provider returns from scanRoots().',
                    $root->directory,
                    $root->package,
                ));

                continue;
            }

            $unique[$directory."\0".$root->package] = new ResolvedRoot($root, $directory);
        }

        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * The .php files below a directory, sorted by path.
     *
     * @return list<SplFileInfo>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = $file;
            }
        }

        ksort($files, SORT_STRING);

        return array_values($files);
    }

    /**
     * The contents of a local file the directory iterator found, or null when it cannot be read.
     */
    private function source(SplFileInfo $file): ?string
    {
        try {
            $handle = $file->openFile('r');
            $size = $file->getSize();
            $source = $size === 0 ? '' : $handle->fread($size);
        } catch (RuntimeException) {
            return null;
        }

        return $source === false ? null : $source;
    }

    /**
     * @param  list<BuildProblem>  $problems
     * @return ReflectionClass<object>|null
     */
    private function load(string $class, string $file, ScanRoot $root, array &$problems): ?ReflectionClass
    {
        try {
            $exists = class_exists($class) || interface_exists($class) || trait_exists($class);
        } catch (Throwable $failure) {
            $problems[] = new BuildProblem(BuildErrorCode::ClassNotLoadable, sprintf(
                'Loading %s from %s in the scan root of %s failed: %s',
                $class,
                $file,
                $root->package,
                $failure->getMessage(),
            ));

            return null;
        }

        if (! $exists) {
            $problems[] = new BuildProblem(BuildErrorCode::ClassNotLoadable, sprintf(
                '%s declares %s, but the autoloader cannot find it. Make the namespace and path match the package\'s PSR-4 mapping in composer.json, then run composer dump-autoload.',
                $file,
                $class,
            ));

            return null;
        }

        return new ReflectionClass($class);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  list<ActionEntry>  $actions
     * @param  list<CommandEntry>  $commands
     * @param  list<DiscoveredHook>  $hooks
     * @param  list<BuildProblem>  $problems
     */
    private function read(ReflectionClass $class, ScanRoot $root, array &$actions, array &$commands, array &$hooks, array &$problems): void
    {
        $attributes = [
            ...$class->getAttributes(Action::class),
            ...$class->getAttributes(Command::class),
            ...$class->getAttributes(Hook::class),
        ];

        if ($attributes === []) {
            return;
        }

        if ($class->isInterface() || $class->isTrait() || $class->isEnum() || $class->isAbstract()) {
            $problems[] = new BuildProblem(BuildErrorCode::NotAConcreteClass, sprintf(
                '%s (%s) is %s, and #[%s] belongs on a concrete class. Move the attribute to the class that implements it.',
                $class->getName(),
                $root->package,
                $this->kind($class),
                $this->shortName($attributes[0]),
            ));

            return;
        }

        $this->checkShape($class, $root, $problems);

        foreach ($attributes as $attribute) {
            try {
                $declaration = $attribute->newInstance();

                if ($declaration instanceof Action) {
                    $actions[] = new ActionEntry($class->getName(), $root->package, $declaration->surfaces);
                } elseif ($declaration instanceof Command) {
                    $commands[] = new CommandEntry($declaration->name(), $declaration->version, $class->getName(), $root->package);
                } elseif ($declaration instanceof Hook) {
                    $hooks[] = new DiscoveredHook(
                        $class->getName(),
                        $root->package,
                        new ReflectionClass($declaration->command)->getName(),
                        $declaration->phase,
                        $declaration->priority,
                        $declaration->budgetMs,
                    );
                }
            } catch (Throwable $invalid) {
                $problems[] = new BuildProblem(BuildErrorCode::InvalidAttribute, sprintf(
                    '#[%s] on %s (%s) is invalid: %s',
                    $this->shortName($attribute),
                    $class->getName(),
                    $root->package,
                    $invalid->getMessage(),
                ));
            }
        }
    }

    /**
     * Reports a #[Command] or #[Action] class that is not a final readonly class (GUARDRAILS 2.1):
     * a command must not change after the pipeline has authorized and validated it, and an action
     * must not be replaced by a subclass the registry does not list. The class's entries are still
     * read, so a hook for the command does not also fail as a hook for an unknown command.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<BuildProblem>  $problems
     */
    private function checkShape(ReflectionClass $class, ScanRoot $root, array &$problems): void
    {
        $declarations = [
            ...$class->getAttributes(Command::class),
            ...$class->getAttributes(Action::class),
        ];

        if ($declarations === [] || ($class->isFinal() && $class->isReadOnly())) {
            return;
        }

        $missing = implode(' and ', array_keys(array_filter(
            ['not final' => ! $class->isFinal(), 'not readonly' => ! $class->isReadOnly()],
        )));

        $problems[] = new BuildProblem(BuildErrorCode::NotFinalReadonly, sprintf(
            '#[%s] on %s (%s) is %s. A command, a WriteAction and a QueryAction are each a final readonly class (GUARDRAILS 2.1). Declare it as final readonly class %s.',
            implode('] and #[', array_map($this->shortName(...), $declarations)),
            $class->getName(),
            $root->package,
            $missing,
            $class->getShortName(),
        ));
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function kind(ReflectionClass $class): string
    {
        return match (true) {
            $class->isInterface() => 'an interface',
            $class->isTrait() => 'a trait',
            $class->isEnum() => 'an enum',
            default => 'an abstract class',
        };
    }

    /**
     * @param  ReflectionAttribute<object>  $attribute
     */
    private function shortName(ReflectionAttribute $attribute): string
    {
        $name = $attribute->getName();
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
