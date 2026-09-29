<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Query;
use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Attributes\UnknownEvent;
use Cbox\Cms\Contracts\Attributes\UnknownLane;
use Cbox\Cms\Contracts\Attributes\UnknownSurface;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\Subscriber;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredAction;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\QueryEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscriberEntry;
use Error;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Finds #[Action], #[Command], #[Query], #[Hook] and #[Subscription] in the scan roots with
 * reflection, at build time only (GUARDRAILS 2.2).
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
        $found = new ScanFindings;
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

                    $this->read($reflection, $root, $found, $problems);
                }
            }
        }

        return new Discovery($found->commands, $found->hooks, $problems, $found->queries, $found->actions, $found->subscribers);
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
     * @param  list<BuildProblem>  $problems
     */
    private function read(ReflectionClass $class, ScanRoot $root, ScanFindings $found, array &$problems): void
    {
        $attributes = [
            ...$class->getAttributes(Action::class),
            ...$class->getAttributes(Command::class),
            ...$class->getAttributes(Query::class),
            ...$class->getAttributes(Hook::class),
            ...$class->getAttributes(Subscription::class),
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

                if ($declaration instanceof Command) {
                    $found->commands[] = new CommandEntry($declaration->name(), $declaration->version, $class->getName(), $root->package);
                } elseif ($declaration instanceof Query) {
                    $found->queries[] = new QueryEntry($declaration->name(), $declaration->version, $class->getName(), $root->package);
                } elseif ($declaration instanceof Action) {
                    $this->readAction($class, $root, $declaration, $found, $problems);
                } elseif ($declaration instanceof Subscription) {
                    $this->readSubscription($class, $root, $declaration, $found, $problems);
                } elseif ($declaration instanceof Hook) {
                    $found->hooks[] = new DiscoveredHook(
                        $class->getName(),
                        $root->package,
                        new ReflectionClass($declaration->command)->getName(),
                        $declaration->phase,
                        $declaration->priority,
                        $declaration->budgetMs,
                    );
                }
            } catch (UnknownSurface $unknown) {
                $problems[] = $this->unknownSurface($class, $root, $unknown->getMessage());
            } catch (UnknownLane $unknown) {
                $problems[] = $this->unknownLane($class, $root, $unknown->getMessage());
            } catch (UnknownEvent $unknown) {
                $problems[] = $this->unknownEvent($class, $root, $unknown->getMessage());
            } catch (Error $error) {
                // An argument such as Surface::Graphql or Lane::Urgent names an enum case that does not exist.
                $problems[] = match (true) {
                    str_starts_with($error->getMessage(), 'Undefined constant '.Surface::class.'::') => $this->unknownSurface($class, $root, $error->getMessage()),
                    str_starts_with($error->getMessage(), 'Undefined constant '.Lane::class.'::') => $this->unknownLane($class, $root, $error->getMessage()),
                    default => $this->invalidAttribute($attribute, $class, $root, $error),
                };
            } catch (Throwable $invalid) {
                $problems[] = $this->invalidAttribute($attribute, $class, $root, $invalid);
            }
        }
    }

    /**
     * Reads an #[Action]: the class implements WriteAction or QueryAction, which gives its kind, and
     * the attribute gives the class it handles, which the compiler resolves.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<BuildProblem>  $problems
     */
    private function readAction(ReflectionClass $class, ScanRoot $root, Action $declaration, ScanFindings $found, array &$problems): void
    {
        $write = $class->implementsInterface(WriteAction::class);
        $query = $class->implementsInterface(QueryAction::class);

        if ($write === $query) {
            $problems[] = new BuildProblem(BuildErrorCode::NotAnAction, sprintf(
                '#[Action] on %s (%s) sits on a class that implements %s. An action implements exactly one of %s and %s (GUARDRAILS 2.1).',
                $class->getName(),
                $root->package,
                $write ? 'both WriteAction and QueryAction' : 'neither WriteAction nor QueryAction',
                WriteAction::class,
                QueryAction::class,
            ));

            return;
        }

        $handles = ltrim($declaration->handles, '\\');

        // The loaded class's own spelling, so the compiler matches it as PHP does; a class that does
        // not load stays as written and is reported by the compiler as unknown.
        if (class_exists($handles)) {
            $handles = new ReflectionClass($handles)->getName();
        }

        $found->actions[] = new DiscoveredAction(
            $class->getName(),
            $root->package,
            $write ? ActionKind::Write : ActionKind::Query,
            $handles,
            $declaration->surfaces,
        );
    }

    /**
     * Reads a #[Subscription]: the class implements Subscriber, and each event class gives its type,
     * which the log stores and the registry answers by.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<BuildProblem>  $problems
     */
    private function readSubscription(ReflectionClass $class, ScanRoot $root, Subscription $declaration, ScanFindings $found, array &$problems): void
    {
        if (! $class->implementsInterface(Subscriber::class)) {
            $problems[] = new BuildProblem(BuildErrorCode::NotASubscriber, sprintf(
                '#[Subscription] on %s (%s) sits on a class that does not implement %s. A subscriber implements it and receives each event in handle() (PRD 7.6).',
                $class->getName(),
                $root->package,
                Subscriber::class,
            ));

            return;
        }

        $events = [];

        foreach ($declaration->events as $event) {
            try {
                $events[] = new SubscribedEvent(new ReflectionClass($event)->getName(), $this->eventType($event));
            } catch (Throwable $failure) {
                $problems[] = $this->unknownEvent($class, $root, sprintf('the type() of the event class %s failed: %s', $event, $failure->getMessage()));

                return;
            }
        }

        $found->subscribers[] = new SubscriberEntry(
            $class->getName(),
            $root->package,
            $declaration->name(),
            $declaration->lane,
            $declaration->projection(),
            $events,
        );
    }

    /**
     * @param  class-string<Event>  $event
     */
    private function eventType(string $event): EventType
    {
        return $event::type();
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function unknownLane(ReflectionClass $class, ScanRoot $root, string $reason): BuildProblem
    {
        return new BuildProblem(BuildErrorCode::UnknownLane, sprintf(
            '#[Subscription] on %s (%s) names a lane that does not exist: %s. The lanes are the cases of %s: %s.',
            $class->getName(),
            $root->package,
            rtrim($reason, '.'),
            Lane::class,
            UnknownLane::cases(),
        ));
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function unknownEvent(ReflectionClass $class, ScanRoot $root, string $reason): BuildProblem
    {
        return new BuildProblem(BuildErrorCode::UnknownEvent, sprintf(
            '#[Subscription] on %s (%s) lists an event it cannot receive: %s. A subscriber lists classes that implement %s.',
            $class->getName(),
            $root->package,
            rtrim($reason, '.'),
            Event::class,
        ));
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function unknownSurface(ReflectionClass $class, ScanRoot $root, string $reason): BuildProblem
    {
        return new BuildProblem(BuildErrorCode::UnknownSurface, sprintf(
            '#[Action] on %s (%s) lists a surface that does not exist: %s. The surfaces are the cases of %s: %s.',
            $class->getName(),
            $root->package,
            rtrim($reason, '.'),
            Surface::class,
            implode(', ', array_map(static fn (Surface $case): string => 'Surface::'.$case->name, Surface::cases())),
        ));
    }

    /**
     * @param  ReflectionAttribute<object>  $attribute
     * @param  ReflectionClass<object>  $class
     */
    private function invalidAttribute(ReflectionAttribute $attribute, ReflectionClass $class, ScanRoot $root, Throwable $invalid): BuildProblem
    {
        return new BuildProblem(BuildErrorCode::InvalidAttribute, sprintf(
            '#[%s] on %s (%s) is invalid: %s',
            $this->shortName($attribute),
            $class->getName(),
            $root->package,
            $invalid->getMessage(),
        ));
    }

    /**
     * Reports a #[Command], #[Query], #[Action] or #[Subscription] class that is not a final
     * readonly class (GUARDRAILS 2.1): a command or query must not change after the pipeline has
     * authorized and validated it, and an action or a subscriber holds no state between calls. The class's entries are still read,
     * so a hook or an action for the command does not also fail as one for an unknown command.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<BuildProblem>  $problems
     */
    private function checkShape(ReflectionClass $class, ScanRoot $root, array &$problems): void
    {
        $shaped = [
            ...$class->getAttributes(Command::class),
            ...$class->getAttributes(Query::class),
            ...$class->getAttributes(Action::class),
            ...$class->getAttributes(Subscription::class),
        ];

        if ($shaped === [] || ($class->isFinal() && $class->isReadOnly())) {
            return;
        }

        $missing = implode(' and ', array_keys(array_filter(
            ['not final' => ! $class->isFinal(), 'not readonly' => ! $class->isReadOnly()],
        )));

        $problems[] = new BuildProblem(BuildErrorCode::NotFinalReadonly, sprintf(
            '#[%s] on %s (%s) is %s. A command, query, action or subscriber is a final readonly class (GUARDRAILS 2.1). Declare it as final readonly class %s.',
            $this->shortName($shaped[0]),
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
