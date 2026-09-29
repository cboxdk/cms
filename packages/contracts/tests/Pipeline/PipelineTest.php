<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Pipeline;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Attributes\UnknownSurface;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\InvalidPipelineValue;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use InvalidArgumentException;

/*
 * The pipeline's values (GUARDRAILS 2.1, PRD 6.2): typed ids with aggregate keys unique across
 * kinds, aggregate versions, the reads a write checks at commit, and #[Action]'s surfaces.
 */

const PIPELINE_UUID = '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f';

const PIPELINE_OTHER = '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e10';

it('parses every typed id from a UUIDv7 and keys each by its kind', function (): void {
    $entry = EntryId::fromString(PIPELINE_UUID);
    $node = NodeId::fromString(PIPELINE_UUID);
    $placement = PlacementId::fromString(PIPELINE_UUID);
    $site = SiteId::fromString(PIPELINE_UUID);
    $actor = ActorId::fromString(PIPELINE_UUID);
    $type = TypeId::fromString(PIPELINE_UUID);

    expect($entry->aggregateKey())->toBe('entry:'.PIPELINE_UUID)
        ->and($node->aggregateKey())->toBe('node:'.PIPELINE_UUID)
        ->and($placement->aggregateKey())->toBe('placement:'.PIPELINE_UUID)
        ->and($site->aggregateKey())->toBe('site:'.PIPELINE_UUID)
        ->and($actor->aggregateKey())->toBe('actor:'.PIPELINE_UUID)
        ->and($type->toString())->toBe(PIPELINE_UUID);

    foreach ([$entry, $node, $placement, $site, $actor, $type] as $id) {
        expect($id->toString())->toBe(PIPELINE_UUID)
            ->and($id->value->value)->toBe(PIPELINE_UUID);
    }

    expect($entry->equals(EntryId::fromString(PIPELINE_UUID)))->toBeTrue()
        ->and($entry->equals(EntryId::fromString(PIPELINE_OTHER)))->toBeFalse()
        ->and($node->equals(NodeId::fromString(PIPELINE_OTHER)))->toBeFalse()
        ->and($placement->equals(PlacementId::fromString(PIPELINE_UUID)))->toBeTrue()
        ->and($placement->equals(PlacementId::fromString(PIPELINE_OTHER)))->toBeFalse()
        ->and($site->equals(SiteId::fromString(PIPELINE_UUID)))->toBeTrue()
        ->and($site->equals(SiteId::fromString(PIPELINE_OTHER)))->toBeFalse()
        ->and($actor->equals(ActorId::fromString(PIPELINE_OTHER)))->toBeFalse()
        ->and($node->equals(NodeId::fromString(PIPELINE_UUID)))->toBeTrue()
        ->and($type->equals(TypeId::fromString(PIPELINE_UUID)))->toBeTrue()
        ->and($type->equals(TypeId::fromString(PIPELINE_OTHER)))->toBeFalse()
        ->and(static fn (): EntryId => EntryId::fromString('not-a-uuid'))->toThrow(InvalidUuid7::class);
});

it('versions aggregates from 1', function (): void {
    expect(AggregateVersion::first()->value)->toBe(1)
        ->and(AggregateVersion::first()->next()->value)->toBe(2)
        ->and(new AggregateVersion(4)->equals(new AggregateVersion(4)))->toBeTrue()
        ->and(new AggregateVersion(4)->equals(new AggregateVersion(5)))->toBeFalse()
        ->and(static fn (): AggregateVersion => new AggregateVersion(0))->toThrow(InvalidPipelineValue::class, 'An aggregate version starts at 1, got 0.');
});

it('holds each read once, sorted by aggregate key', function (): void {
    $entry = EntryId::fromString(PIPELINE_UUID);
    $actor = ActorId::fromString(PIPELINE_UUID);
    $variant = new VariantRef($entry, VariantKey::shared());

    $reads = new ReadVersions(
        ReadVersion::at($variant, new AggregateVersion(3)),
        ReadVersion::absent($entry),
        ReadVersion::at($actor, AggregateVersion::first()),
    );

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $reads->reads))
        ->toBe(['actor:'.PIPELINE_UUID, 'entry:'.PIPELINE_UUID, $variant->aggregateKey()])
        ->and($reads->of($entry)?->existed())->toBeFalse()
        ->and($reads->of($entry)?->version)->toBeNull()
        ->and($reads->of(new VariantRef($entry, VariantKey::shared()))?->version?->value)->toBe(3)
        ->and($reads->of($actor)?->existed())->toBeTrue()
        ->and($reads->of(NodeId::fromString(PIPELINE_UUID)))->toBeNull()
        ->and($reads->isEmpty())->toBeFalse()
        ->and(new ReadVersions()->isEmpty())->toBeTrue()
        ->and(static fn (): ReadVersions => new ReadVersions(ReadVersion::absent($entry), ReadVersion::at(EntryId::fromString(PIPELINE_UUID), AggregateVersion::first())))
        ->toThrow(InvalidPipelineValue::class, 'The aggregate "entry:'.PIPELINE_UUID.'" is read twice in one write.');
});

it('lists #[Action]\'s surfaces once each, in the order of the enum', function (): void {
    $action = new Action('App\\Notes\\SaveNote', [Surface::Cli, Surface::Rest, Surface::Mcp]);

    expect($action->handles)->toBe('App\\Notes\\SaveNote')
        ->and($action->surfaces)->toBe([Surface::Rest, Surface::Mcp, Surface::Cli])
        ->and($action->exposes(Surface::Mcp))->toBeTrue()
        ->and($action->exposes(Surface::Inertia))->toBeFalse()
        ->and(new Action('App\\Notes\\SaveNote')->surfaces)->toBe([])
        ->and(new Action('App\\Notes\\SaveNote', Surface::cases())->surfaces)->toBe(Surface::cases())
        ->and(array_map(static fn (Surface $surface): string => $surface->value, Surface::cases()))->toBe(['rest', 'inertia', 'mcp', 'cli'])
        ->and(static fn (): Action => new Action('App\\Notes\\SaveNote', [Surface::Rest, Surface::Mcp, Surface::Rest]))
        ->toThrow(InvalidArgumentException::class, 'The surface "rest" is listed 2 times in #[Action].');
});

it('refuses an #[Action] that names no class it handles', function (string $handles): void {
    expect(static fn (): Action => new Action($handles))
        ->toThrow(InvalidArgumentException::class, '#[Action] names no class it handles. Give the command or query class, for example handles: SaveNote::class.');
})->with(['empty' => [''], 'blank' => ['  ']]);

it('refuses a surface that is not a case of the enum as UnknownSurface', function (): void {
    expect(static fn (): Action => new Action('App\\Notes\\SaveNote', [Surface::Rest, 'rest']))
        ->toThrow(UnknownSurface::class, '#[Action] lists "rest", which is not a surface. List cases of Cbox\\Cms\\Contracts\\Attributes\\Surface: Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli.')
        ->and(new UnknownSurface('x'))->toBeInstanceOf(InvalidArgumentException::class);
});
