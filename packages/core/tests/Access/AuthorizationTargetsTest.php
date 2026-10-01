<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Core\Entries\Domain\Dto\CreateEntryAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\ReleaseVariantAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\ReviseEntryAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Placements\Domain\Dto\CreatePlacementAggregates;
use Cbox\Cms\Core\Placements\Domain\Dto\SetPlacementWindowAggregates;
use Cbox\Cms\Core\Placements\Domain\Dto\SlugClaim;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;
use Cbox\Cms\Core\Publishing\Domain\Dto\PublishEntryAggregates;
use Cbox\Cms\Core\Publishing\Domain\Dto\UnpublishEntryAggregates;
use DateTimeImmutable;

/*
 * The nodes the kernel's commands are authorized on (PRD 5.10): content changes on the entry's
 * home, placement changes on the placement's node in their locales, entry.publish on both, and
 * nothing when the aggregate that names the node was read as absent.
 */

const TARGET_ENTRY = '0192a0c0-0000-7000-8000-0000000051e1';

const TARGET_HOME = '0192a0c0-0000-7000-8000-0000000051a1';

const TARGET_NODE = '0192a0c0-0000-7000-8000-0000000051a2';

const TARGET_PLACEMENT = '0192a0c0-0000-7000-8000-0000000051c1';

function targetEntry(): StoredEntry
{
    return new StoredEntry(EntryId::fromString(TARGET_ENTRY), TypeId::fromString('0192a0c0-0000-7000-8000-000000005130'), NodeId::fromString(TARGET_HOME), new AggregateVersion(1), null);
}

function targetPlacement(): StoredPlacement
{
    return new StoredPlacement(PlacementId::fromString(TARGET_PLACEMENT), EntryId::fromString(TARGET_ENTRY), NodeId::fromString(TARGET_NODE), new AggregateVersion(1), []);
}

/**
 * @param  list<AuthorizationTarget>  $targets
 * @return list<array{string, string|null}>
 */
function targetsOf(array $targets): array
{
    return array_map(static fn (AuthorizationTarget $target): array => [$target->node->toString(), $target->locale?->value], $targets);
}

it('authorizes the entry commands on the entry\'s home, and on the home a create names', function (): void {
    $entry = EntryId::fromString(TARGET_ENTRY);

    expect(targetsOf(new CreateEntryAggregates($entry, null, NodeId::fromString(TARGET_HOME), null)->authorizationTargets()))->toBe([[TARGET_HOME, null]])
        ->and(targetsOf(new ReviseEntryAggregates($entry, targetEntry())->authorizationTargets()))->toBe([[TARGET_HOME, null]])
        ->and(targetsOf(new ReleaseVariantAggregates($entry, targetEntry())->authorizationTargets()))->toBe([[TARGET_HOME, null]])
        ->and(targetsOf(new UnpublishEntryAggregates($entry, targetEntry(), [], new DateTimeImmutable('2026-03-10T12:00:00Z'))->authorizationTargets()))->toBe([[TARGET_HOME, null]])
        ->and(new ReviseEntryAggregates($entry, null)->authorizationTargets())->toBe([])
        ->and(new ReleaseVariantAggregates($entry, null)->authorizationTargets())->toBe([])
        ->and(new UnpublishEntryAggregates($entry, null, [], new DateTimeImmutable('2026-03-10T12:00:00Z'))->authorizationTargets())->toBe([]);
});

it('authorizes the placement commands on the placement\'s node, in the locales they act in', function (): void {
    $node = NodeId::fromString(TARGET_NODE);
    $placement = PlacementId::fromString(TARGET_PLACEMENT);
    $at = new DateTimeImmutable('2026-03-10T12:00:00Z');
    $claim = static fn (string $locale): SlugClaim => new SlugClaim(new PlacementSlugRef($node, new Locale($locale), new Slug('harbour')), false);
    $create = static fn (SlugClaim ...$slugs): CreatePlacementAggregates => new CreatePlacementAggregates($placement, null, EntryId::fromString(TARGET_ENTRY), null, $node, null, SiteId::fromString('0192a0c0-0000-7000-8000-0000000051b1'), null, array_values($slugs), [], $at);

    expect(targetsOf($create($claim('da'), $claim('en'))->authorizationTargets()))->toBe([[TARGET_NODE, 'da'], [TARGET_NODE, 'en']])
        ->and(targetsOf($create()->authorizationTargets()))->toBe([[TARGET_NODE, null]])
        ->and(targetsOf(new SetPlacementWindowAggregates($placement, new AggregateVersion(1), targetPlacement(), new Locale('da'), null, $at)->authorizationTargets()))->toBe([[TARGET_NODE, 'da']])
        ->and(new SetPlacementWindowAggregates($placement, null, null, new Locale('da'), null, $at)->authorizationTargets())->toBe([]);
});

it('authorizes entry.publish on the entry\'s home and on the home placement\'s node in its locale', function (): void {
    $entry = EntryId::fromString(TARGET_ENTRY);
    $placement = PlacementId::fromString(TARGET_PLACEMENT);
    $at = new DateTimeImmutable('2026-03-10T12:00:00Z');
    $publish = static fn (?StoredEntry $stored, ?StoredPlacement $placed): PublishEntryAggregates => new PublishEntryAggregates($entry, $stored, null, $placement, null, $placed, new Locale('da'), [], $at);

    expect(targetsOf($publish(targetEntry(), targetPlacement())->authorizationTargets()))->toBe([[TARGET_HOME, null], [TARGET_NODE, 'da']])
        ->and(targetsOf($publish(targetEntry(), null)->authorizationTargets()))->toBe([[TARGET_HOME, null]])
        ->and($publish(null, targetPlacement())->authorizationTargets())->toBe([]);
});
