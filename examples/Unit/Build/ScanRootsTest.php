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
            'addon' => null,
            'budget_ms' => 5,
            'class' => TrimNoteTitle::class,
            'command' => 'note.publish',
            'command_class' => PublishNote::class,
            'command_version' => 1,
            'package' => 'acme/cms-notes',
            'phase' => 'transform',
            'priority' => 20,
            'reads' => null,
        ], $hooks['entries']);
    }

    #[Test]
    public function it_registers_the_query_action_under_the_querys_name_and_version(): void
    {
        self::assertSame(0, $this->build(NotesServiceProvider::class));

        $actions = require $this->registryFile('actions');
        self::assertIsArray($actions);
        self::assertSame('actions', $actions['registry']);
        self::assertIsArray($actions['entries']);

        // The registry also holds the kernel's own actions; these are the package's.
        $notes = [];

        foreach ($actions['entries'] as $entry) {
            if (is_array($entry) && ($entry['package'] ?? null) === 'acme/cms-notes') {
                $notes[] = $entry;
            }
        }

        self::assertSame([[
            'class' => FindNoteAction::class,
            'command' => 'note.find',
            'command_class' => FindNote::class,
            'command_version' => 1,
            'kind' => 'query',
            'package' => 'acme/cms-notes',
            'surfaces' => ['rest', 'cli'],
        ]], $notes);

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
