---
title: Build declarations
weight: 36
description: "Declare commands, queries, actions and hooks with attributes, give cms:build your scan roots, and read the registries it compiles."
---

# Build declarations: scan roots, commands, queries, actions and hooks

<!-- extension-point: Cbox\Cms\Contracts\Build\DeclaresScanRoots -->
<!-- extension-point: Cbox\Cms\Contracts\Attributes\Command -->
<!-- extension-point: Cbox\Cms\Contracts\Attributes\Query -->
<!-- extension-point: Cbox\Cms\Contracts\Attributes\Hook -->

A package tells the CMS about its commands, queries, actions and hooks with attributes on its classes, and tells `cms:build` where those classes are with its service provider. `cms:build` reads the attributes with reflection and compiles three registries to `bootstrap/cache/cms/` (PRD 13.2). Reflection runs only there, at build time; at run time the CMS reads the compiled files (GUARDRAILS 2.2).

Run `cms:build` from Composer's `post-autoload-dump` script, so it follows every `composer install`, `composer update` and `composer dump-autoload`, and in every deploy. The files are not committed. `cms:doctor` fails its check `registry.cache` when the files are missing, damaged or older than `vendor/composer/installed.json`, so a registry that misses a newly installed package does not go unnoticed.

The registry holds the declarations: the classes, and the names, versions, surfaces, phases, priorities and budgets their attributes give. It does not call an action or a hook; the kernel asks it for the action of a command or query and calls it through the pipeline.

## Scan roots

A package's service provider implements `Cbox\Cms\Contracts\Build\DeclaresScanRoots` and returns a list of `Cbox\Cms\Contracts\Build\ScanRoot` from `scanRoots()`. A scan root is two things:

| Argument | Value |
|---|---|
| `package` | The Composer package name, such as `acme/cms-notes`. It names the package in the registry and in build errors, and it orders hooks with the same priority. |
| `directory` | An absolute path. Use `__DIR__`, the directory of the provider, usually the package's `src`. |

`cms:build` scans every `.php` file below the directory, in sorted order, and loads each class it declares through the autoloader, so every class there must be autoloadable: the namespace and path follow the package's PSR-4 mapping. A class without one of the attributes `#[Action]`, `#[Command]`, `#[Query]` and `#[Hook]` is left out.

`cms:build` asks every registered provider that implements the interface. It registers the deferred providers first, so a deferred provider is asked too. A package whose provider declares no scan root has nothing in the registry, even when its classes carry the attributes. A directory that is not readable, or a class that is in the scan roots of two packages, stops the build.

## Commands

`#[Command(name, version)]` sits on the command class: the data a caller sends, such as `#[Command('note.publish', version: 1)]` (GUARDRAILS 2.1).

- The name is at least two segments separated by dots, and each segment is snake_case: a lowercase letter, then lowercase letters, digits and underscores. `note.publish` and `entry.release_variant` are names; `publish`, `Note.publish` and `note..publish` are not.
- The version is an integer from 1.
- The class is a `final readonly class`.
- A name and version belong to one class. A new shape of the command gets the next version, and both classes stay declared.

## Queries

`#[Query(name, version)]` sits on the query class, the input of a read, such as `#[Query('note.find', version: 1)]`. The name and version follow the rules of a command's, and the class is a `final readonly class`. Commands and queries share the names: a name and version belong to one command or one query, so `note.find` version 1 cannot be both.

## Actions

`#[Action(handles: ..., surfaces: [...])]` sits on each write and query action (GUARDRAILS 2.1), see [commands and write actions](commands.md) and [queries](queries.md):

| Argument | Value |
|---|---|
| `handles` | The class the action handles. A class that implements `WriteAction` handles a command declared with `#[Command]`, and a class that implements `QueryAction` handles a query declared with `#[Query]`. A scan root must register it, in the same package or another. |
| `surfaces` | The cases of `Cbox\Cms\Contracts\Attributes\Surface` it is exposed on, each once. Empty, the action is called only by the kernel's own issuers. |

- The action class is a `final readonly class` and implements exactly one of `WriteAction` and `QueryAction`, which gives its kind, `write` or `query`.
- A command or query has one action. A new version of a command gets its own action, or the same action class handles only one of them.

## Hooks

`#[Hook(command: ..., phase: ..., priority: ..., budgetMs: ...)]` sits on a hook class and says which command it runs for, in which phase, in which order and within which time budget (GUARDRAILS 2.4, PRD 6.3):

| Argument | Value |
|---|---|
| `command` | The command class, for example `PublishNote::class`. The class must exist and carry `#[Command]`, and a scan root must register it, in the same package or another. |
| `phase` | A case of the enum `Cbox\Cms\Contracts\Attributes\Phase`, see below. |
| `priority` | An integer. The lowest priority runs first. |
| `budgetMs` | The hook's time budget in milliseconds, from 1 to 20 (`Hook::MAX_BUDGET_MS`). |

The phases are those of the command pipeline in which hooks run (PRD 6.2):

| Phase | Value | Pipeline phase | The hook may |
|---|---|---|---|
| `Phase::Authorize` | `authorize` | 2, authorize | reject the command with a reason; it never grants access |
| `Phase::Transform` | `transform` | 4, transform | change declared fields in the plan, without IO |
| `Phase::Validate` | `validate` | 5, validate | add errors; it never removes the core's errors |

Hooks are deterministic and do no network IO; work that needs IO belongs in a subscriber (PRD 6.3). Hooks of one command and phase run by priority, then by package name, then by class name, so the order never depends on the order in which packages are installed.

## The compiled registries

`cms:build` writes one PHP file per registry to `bootstrap/cache/cms/`: `actions.php`, `commands.php` and `hooks.php`. It removes any other file in that directory, which it owns, except its lock file `.lock`: two builds that run at the same time write the cache one after the other, so it always holds the files of one build. Each file returns an array with these keys:

| Key | Value |
|---|---|
| `build` | The sha256 of the entries of every registry. The files of one build carry the same value. |
| `entries` | The list of entries, sorted as below. |
| `format` | `4`, the format of the files. A cache of another format is refused until `cms:build` runs again. |
| `registry` | `actions`, `commands` or `hooks`. |

The keys of every entry are in alphabetical order:

| Registry | Entry keys | Sorted by |
|---|---|---|
| `actions` | `class`, `command` (the name of the command or query it handles), `command_class`, `command_version`, `kind` (`write` or `query`), `package`, `surfaces` (the values of the surfaces, in the order of the enum) | command name, then command version |
| `commands` | `class`, `name`, `package`, `version` | name, then version |
| `hooks` | `budget_ms`, `class`, `command` (the command's name), `command_class`, `command_version`, `package`, `phase` (the value of the phase), `priority` | command name, command version, phase in pipeline order (authorize, transform, validate), priority with the lowest first, package, class |

A file holds no time and no path, so two builds of the same code give the same bytes. Read the files, never edit them: run `cms:build` again instead.

## Build errors

`cms:build` checks every declaration before it writes anything. A build with problems writes nothing, leaves the files of the last good build as they were, prints every problem as `[<code>] <message>` and exits with 65. When a file cannot be written, it prints the reason with the code `registry_cache_unwritable` and exits with 73. On success it prints the number of entries in each registry and exits with 0.

| Code | Problem |
|---|---|
| `registry_invalid_scan_root` | A declared scan root is not a readable directory. |
| `registry_class_not_loadable` | A class in a scan root cannot be autoloaded, or loading it failed. |
| `registry_invalid_attribute` | An attribute's arguments are invalid, such as a command name with one segment, version 0, a hook's command class that does not exist or has no `#[Command]`, a budget outside 1 to 20 ms, or a surface listed twice. |
| `registry_not_a_concrete_class` | An attribute sits on an interface, trait, enum or abstract class. |
| `registry_not_final_readonly` | A `#[Command]`, `#[Query]` or `#[Action]` sits on a class that is not a `final readonly class`. |
| `registry_class_in_two_roots` | Two packages' scan roots contain the same class. |
| `registry_duplicate_command` | Two classes declare the same command or query name and version. |
| `registry_unknown_hook_command` | A hook runs for a command class that no scan root registers: the package that holds the command declares no scan root, or its provider is not registered. |
| `registry_not_an_action` | An `#[Action]` sits on a class that implements neither `WriteAction` nor `QueryAction`, or both. |
| `registry_unknown_action_command` | An action handles a class that is not a registered command (a write action) or query (a query action): the class does not exist, lacks the attribute, is the other kind, or no scan root registers it. |
| `registry_duplicate_action` | Two actions handle the same command or query. |
| `registry_unknown_surface` | An `#[Action]` lists a surface that is not a case of `Surface`, such as `'rest'` or `Surface::Graphql`. |

## Example

Two small packages. `acme/cms-notes` declares the command `note.publish` and a transform hook on its own command, and the query `note.find` with its query action:

<!-- example-file: examples/Unit/Build/Notes/NotesServiceProvider.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the package acme/cms-notes. Its scan root is the directory it lies in,
 * so cms:build registers the command, the hook, the query and its action next to it.
 */
final class NotesServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-notes', __DIR__)];
    }
}
```

<!-- example-file: examples/Unit/Build/Notes/PublishNote.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Command;

/**
 * The command that publishes a note, version 1 of note.publish.
 */
#[Command('note.publish', version: 1)]
final readonly class PublishNote
{
    public function __construct(
        public string $title,
    ) {}
}
```

<!-- example-file: examples/Unit/Build/Notes/TrimNoteTitle.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;

/**
 * A transform hook of the notes package on its own command, with priority 20 and a budget of 5 ms.
 */
#[Hook(command: PublishNote::class, phase: Phase::Transform, priority: 20, budgetMs: 5)]
final readonly class TrimNoteTitle {}
```

<!-- example-file: examples/Unit/Build/Notes/FindNote.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The query that finds a note by its title, version 1 of note.find.
 */
#[QueryType('note.find', version: 1)]
final readonly class FindNote implements Query
{
    public function __construct(
        public string $title,
    ) {}
}
```

<!-- example-file: examples/Unit/Build/Notes/FoundNote.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of note.find: whether a note has the title.
 */
final readonly class FoundNote implements Result
{
    public function __construct(
        public bool $found,
    ) {}
}
```

<!-- example-file: examples/Unit/Build/Notes/FindNoteAction.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Override;

/**
 * The query action of note.find, exposed on REST and the command line. cms:build registers it in
 * actions.php under the name and version FindNote declares.
 *
 * @implements QueryAction<FindNote, FoundNote>
 */
#[Action(handles: FindNote::class, surfaces: [Surface::Cli, Surface::Rest])]
final readonly class FindNoteAction implements QueryAction
{
    /**
     * @param  list<string>  $titles  the titles of the notes there are
     */
    public function __construct(
        private array $titles,
    ) {}

    /**
     * @param  FindNote  $query
     */
    #[Override]
    public function handle(Query $query): FoundNote
    {
        return new FoundNote(in_array($query->title, $this->titles, true));
    }
}
```

`acme/cms-tagging` hooks into the notes package's command:

<!-- example-file: examples/Unit/Build/Tagging/TaggingServiceProvider.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Tagging;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The service provider of the package acme/cms-tagging, which hooks into the notes package.
 */
final class TaggingServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-tagging', __DIR__)];
    }
}
```

<!-- example-file: examples/Unit/Build/Tagging/TagPublishedNote.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Tagging;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Examples\Unit\Build\Notes\PublishNote;

/**
 * A transform hook of the tagging package on the notes package's command. Its priority, 10, is
 * lower than that of the notes package's own hook, so it runs first.
 */
#[Hook(command: PublishNote::class, phase: Phase::Transform, priority: 10, budgetMs: 2)]
final readonly class TagPublishedNote {}
```

The test case is a Testbench application with the installed packages discovered, as in an application, and with the providers of the repository's `testbench.yaml` registered through `WithWorkbench`. It gives the application a bootstrap directory of its own, so `cms:build` never writes the skeleton's `bootstrap/cache/cms`, and it registers the packages' providers and runs `cms:build` by its Artisan name:

<!-- example-file: examples/Unit/Build/BuildTestCase.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build;

use FilesystemIterator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * A Testbench application for testing a package's build declarations. The installed packages'
 * providers are discovered, as in an application, and WithWorkbench registers those of the
 * repository's testbench.yaml, which in cboxdk/cms's own repository, where cboxdk/cms is the root
 * package that discovery does not see, are cboxdk/cms's; so cboxdk/cms brings cms:build. The
 * bootstrap directory is a temporary directory of the test's own, so cms:build writes
 * bootstrap/cache/cms there and never into the Testbench skeleton, which other tests read; it is
 * removed after the test.
 */
abstract class BuildTestCase extends TestCase
{
    use WithWorkbench;

    /** Discover the service providers of the installed packages. */
    #[Override]
    protected $enablesPackageDiscoveries = true;

    private string $bootstrap = '';

    #[Override]
    protected function defineEnvironment($app): void
    {
        $this->bootstrap = sys_get_temp_dir().'/cms-build-example-'.bin2hex(random_bytes(8));
        mkdir($this->bootstrap, 0o700);

        $app->useBootstrapPath($this->bootstrap);
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        new Filesystem()->deleteDirectory($this->bootstrap);
    }

    /**
     * Registers the packages' service providers and runs cms:build. Returns its exit code, and
     * checks that it left the skeleton's bootstrap/cache/cms as it was.
     *
     * @param  class-string<ServiceProvider>  ...$providers
     */
    protected function build(string ...$providers): int
    {
        foreach ($providers as $provider) {
            app()->register($provider);
        }

        $skeleton = $this->skeletonCache();
        $status = app(Kernel::class)->call('cms:build');

        self::assertSame($skeleton, $this->skeletonCache(), 'cms:build changed the Testbench skeleton\'s bootstrap/cache/cms.');

        return $status;
    }

    /**
     * What the last cms:build printed.
     */
    protected function buildOutput(): string
    {
        return app(Kernel::class)->output();
    }

    /**
     * The directory cms:build writes to: bootstrap/cache/cms below the application's bootstrap path.
     */
    protected function registryDirectory(): string
    {
        return app()->bootstrapPath('cache/cms');
    }

    /**
     * The compiled file of a registry: actions, commands or hooks.
     */
    protected function registryFile(string $registry): string
    {
        return $this->registryDirectory().'/'.$registry.'.php';
    }

    /**
     * The sha1 of every file under the Testbench skeleton's bootstrap/cache/cms, by path.
     *
     * @return array<string, string>
     */
    private function skeletonCache(): array
    {
        $directory = default_skeleton_path('bootstrap/cache/cms');

        if ($directory === false) {
            return [];
        }

        $hashes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $hashes[$file->getPathname()] = sha1_file($file->getPathname()) ?: 'unreadable';
            }
        }

        ksort($hashes, SORT_STRING);

        return $hashes;
    }
}
```

The test reads the compiled `actions.php`, `commands.php` and `hooks.php`. With both packages the tagging hook runs first, because its priority is lower. The tagging package without the notes package is a hook on a command that no scan root registers, and the build writes nothing:

<!-- example: examples/Unit/Build/ScanRootsTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Build;

use Examples\Unit\Build\Notes\FindNote;
use Examples\Unit\Build\Notes\FindNoteAction;
use Examples\Unit\Build\Notes\FoundNote;
use Examples\Unit\Build\Notes\NotesServiceProvider;
use Examples\Unit\Build\Notes\PublishNote;
use Examples\Unit\Build\Notes\TrimNoteTitle;
use Examples\Unit\Build\Tagging\TaggingServiceProvider;
use Examples\Unit\Build\Tagging\TagPublishedNote;
use PHPUnit\Framework\Attributes\Test;

/**
 * cms:build compiles what the scan roots of the notes and tagging packages declare, and refuses a
 * hook on a command that no scan root registers.
 */
final class ScanRootsTest extends BuildTestCase
{
    #[Test]
    public function it_compiles_the_command_and_the_hook_of_a_package(): void
    {
        self::assertSame(0, $this->build(NotesServiceProvider::class));
        self::assertStringContainsString('Registry written to '.$this->registryDirectory().'.', $this->buildOutput());

        $commands = require $this->registryFile('commands');
        self::assertIsArray($commands);
        self::assertSame('commands', $commands['registry']);
        self::assertIsArray($commands['entries']);
        self::assertContains([
            'class' => PublishNote::class,
            'name' => 'note.publish',
            'package' => 'acme/cms-notes',
            'version' => 1,
        ], $commands['entries']);

        $hooks = require $this->registryFile('hooks');
        self::assertIsArray($hooks);
        self::assertSame('hooks', $hooks['registry']);
        self::assertIsArray($hooks['entries']);
        self::assertContains([
            'budget_ms' => 5,
            'class' => TrimNoteTitle::class,
            'command' => 'note.publish',
            'command_class' => PublishNote::class,
            'command_version' => 1,
            'package' => 'acme/cms-notes',
            'phase' => 'transform',
            'priority' => 20,
        ], $hooks['entries']);
    }

    #[Test]
    public function it_registers_the_query_action_under_the_querys_name_and_version(): void
    {
        self::assertSame(0, $this->build(NotesServiceProvider::class));
        self::assertStringContainsString('actions: 1', $this->buildOutput());

        $actions = require $this->registryFile('actions');
        self::assertIsArray($actions);
        self::assertSame('actions', $actions['registry']);
        self::assertSame([[
            'class' => FindNoteAction::class,
            'command' => 'note.find',
            'command_class' => FindNote::class,
            'command_version' => 1,
            'kind' => 'query',
            'package' => 'acme/cms-notes',
            'surfaces' => ['rest', 'cli'],
        ]], $actions['entries']);

        // The registry names the action; the query pipeline calls it.
        self::assertEquals(new FoundNote(true), new FindNoteAction(['Groceries'])->handle(new FindNote('Groceries')));
    }

    #[Test]
    public function it_runs_the_hook_with_the_lowest_priority_first(): void
    {
        self::assertSame(0, $this->build(NotesServiceProvider::class, TaggingServiceProvider::class));

        $hooks = require $this->registryFile('hooks');
        self::assertIsArray($hooks);
        self::assertIsArray($hooks['entries']);

        $classes = array_column($hooks['entries'], 'class');
        $tagging = array_search(TagPublishedNote::class, $classes, true);
        $notes = array_search(TrimNoteTitle::class, $classes, true);
        self::assertIsInt($tagging);
        self::assertIsInt($notes);
        self::assertLessThan($notes, $tagging);
    }

    #[Test]
    public function it_refuses_a_hook_on_a_command_that_no_scan_root_registers_and_writes_nothing(): void
    {
        // The tagging package without the notes package: its hook's command is in no scan root.
        self::assertSame(65, $this->build(TaggingServiceProvider::class));
        self::assertStringContainsString(
            '[registry_unknown_hook_command] Hook '.TagPublishedNote::class.' (acme/cms-tagging) runs for command class '.PublishNote::class.', which no scan root registers.',
            $this->buildOutput(),
        );
        self::assertDirectoryDoesNotExist($this->registryDirectory());
    }
}
```
