<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredAction;
use Cbox\Cms\Core\Registry\Domain\Dto\DiscoveredHook;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Registry\Domain\Dto\QueryEntry;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;

/**
 * A registry for the actions that inspect it, cms:actions and cms:hooks, compiled by the
 * RegistryCompiler from declarations found in no particular order: two versions of note.create,
 * note.archive without hooks, the query note.find, and five hooks of note.create v1 in every
 * phase, two of them with the same phase and priority in two packages, and one of v2.
 */
final class InspectedRegistry
{
    public const string CORE = 'acme/notes';

    public const string OTHER = 'acme/audit';

    public static function compiled(): CompiledRegistry
    {
        $create = 'Acme\Notes\CreateNote';
        $createV2 = 'Acme\Notes\CreateNoteV2';

        return new RegistryCompiler()->compile(new Discovery(
            [
                new CommandEntry(new CommandName('note.create'), 2, $createV2, self::CORE),
                new CommandEntry(new CommandName('note.archive'), 1, 'Acme\Notes\ArchiveNote', self::CORE),
                new CommandEntry(new CommandName('note.create'), 1, $create, self::CORE),
            ],
            [
                new DiscoveredHook('Acme\Notes\RequireTitle', self::CORE, $create, Phase::Validate, 0, 5),
                new DiscoveredHook('Acme\Audit\TrimTitle', self::OTHER, $create, Phase::Transform, 1, 3),
                new DiscoveredHook('Acme\Notes\CheckQuota', self::CORE, $create, Phase::Authorize, 50, 2),
                new DiscoveredHook('Acme\Notes\TrimTitle', self::CORE, $create, Phase::Transform, 1, 4),
                new DiscoveredHook('Acme\Notes\SlugTitle', self::CORE, $create, Phase::Transform, 0, 1),
                new DiscoveredHook('Acme\Notes\RequireBody', self::CORE, $createV2, Phase::Validate, 10, 20),
            ],
            [],
            [new QueryEntry(new CommandName('note.find'), 1, 'Acme\Notes\FindNote', self::CORE)],
            [
                new DiscoveredAction('Acme\Notes\FindNoteAction', self::CORE, ActionKind::Query, 'Acme\Notes\FindNote', [Surface::Rest]),
                new DiscoveredAction('Acme\Notes\CreateNoteAction', self::CORE, ActionKind::Write, $create, [Surface::Rest, Surface::Cli]),
                new DiscoveredAction('Acme\Notes\CreateNoteV2Action', self::CORE, ActionKind::Write, $createV2, []),
                new DiscoveredAction('Acme\Notes\ArchiveNoteAction', self::CORE, ActionKind::Write, 'Acme\Notes\ArchiveNote', [Surface::Inertia]),
            ],
        ));
    }

    public static function cache(): FakeRegistryCache
    {
        $cache = new FakeRegistryCache;
        $cache->write(self::compiled());

        return $cache;
    }
}
