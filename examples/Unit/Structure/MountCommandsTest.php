<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\MountCreated;
use Cbox\Cms\Contracts\Plans\Mutations\MountOverrideSet;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateMount;
use Cbox\Cms\Core\Structure\Domain\Commands\SetMountOverride;
use Cbox\Cms\Core\Structure\Domain\Events\MountCreated as MountCreatedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\MountCreatedV1;
use Cbox\Cms\Core\Structure\Domain\Events\MountOverrideSet as MountOverrideSetEvent;
use Cbox\Cms\Core\Structure\Domain\Events\MountOverrideSetV1;

// A regional site shows the national section without copying a single placement: mount.create
// puts a mount below the regional root that shows the national section, and the regional desk
// hides one story on it with mount.set_override, which answers 404 at the regional URL while the
// national site keeps showing it. The ids are the caller's, so a repeat with the same idempotency
// key is the same mount.

const MOUNT_EXAMPLE_REGIONAL_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000d11';

const MOUNT_EXAMPLE_MOUNT = '01936f5e-8a2b-7c3d-9e4f-000000000d12';

const MOUNT_EXAMPLE_NATIONAL = '01936f5e-8a2b-7c3d-9e4f-000000000d13';

const MOUNT_EXAMPLE_ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000000d14';

it('creates a mount below a parent showing a source, and expects the mount absent', function (): void {
    $create = new CreateMount(NodeId::fromString(MOUNT_EXAMPLE_MOUNT), NodeId::fromString(MOUNT_EXAMPLE_REGIONAL_ROOT), NodeId::fromString(MOUNT_EXAMPLE_NATIONAL));
    $expected = $create->expectedVersions()->reads;

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $expected))
        ->toBe(['node:'.MOUNT_EXAMPLE_MOUNT])
        ->and(array_map(static fn (ReadVersion $read): bool => $read->existed(), $expected))->toBe([false])
        ->and($create->source->toString())->toBe(MOUNT_EXAMPLE_NATIONAL);
});

it('puts the mount on its parent\'s path with its own label below it, and never names itself as the source', function (): void {
    $mount = NodeId::fromString(MOUNT_EXAMPLE_MOUNT);
    $parent = NodeId::fromString(MOUNT_EXAMPLE_REGIONAL_ROOT);
    $path = new NodePath(NodeCreated::label($parent).'.'.NodeCreated::label($mount));
    $created = new MountCreated($mount, $parent, NodeId::fromString(MOUNT_EXAMPLE_NATIONAL), $path);
    $event = new MountCreatedEvent(1, new MountCreatedV1($mount, $parent, $created->source));

    expect($created->aggregate()->aggregateKey())->toBe('node:'.MOUNT_EXAMPLE_MOUNT)
        ->and(MountCreatedEvent::type()->name)->toBe('mount.created')
        ->and(array_keys($event->payload()->data()->fields()))->toBe(['node', 'parent', 'source'])
        ->and(fn (): MountCreated => new MountCreated($mount, $parent, $mount, $path))
        ->toThrow(InvalidArgumentException::class, 'itself as its source');
});

it('hides an entry on the mount, which only a person may undo while the source shows the entry', function (): void {
    $mount = NodeId::fromString(MOUNT_EXAMPLE_MOUNT);
    $entry = EntryId::fromString(MOUNT_EXAMPLE_ENTRY);
    $hide = new SetMountOverride($mount, new AggregateVersion(1), $entry, true);
    $hidden = new MountOverrideSet($mount, $entry, hidden: true, visible: true);
    $shown = new MountOverrideSet($mount, $entry, hidden: false, visible: true);
    $quiet = new MountOverrideSet($mount, $entry, hidden: false, visible: false);
    $event = new MountOverrideSetEvent(2, new MountOverrideSetV1($mount, $entry, true));

    expect(array_map(static fn (ReadVersion $read): ?int => $read->version?->value, $hide->expectedVersions()->reads))->toBe([1])
        ->and($hidden->makesPublic())->toBeFalse()
        ->and($shown->makesPublic())->toBeTrue()
        ->and($quiet->makesPublic())->toBeFalse()
        ->and(VisibilityDecision::MountHidden->rung())->toBe(6)
        ->and(MountOverrideSetEvent::type()->name)->toBe('mount.override_set')
        ->and(array_keys($event->payload()->data()->fields()))->toBe(['entry', 'hidden', 'node']);
});
