<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Entries\Actions\ReleaseVariantAction;
use Cbox\Cms\Core\Entries\Actions\VariantReleasePlanner;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Dto\ReleaseVariantAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryReader;
use LogicException;

/*
 * The planner of a release, which variant.release and composite commands plan with (GUARDRAILS
 * 2.1, PRD 5.6): VariantReleased of the revision for the entry's own type, or an empty plan when
 * that revision is released already. It and the action refuse to plan for a variant or an entry
 * read as absent, which the kernel's check of the expected version rules out before it plans.
 */

function plannedEntry(?StoredHead $head): StoredEntry
{
    return new StoredEntry(EntryActionWorld::entry(), EntryActionWorld::type(), EntryActionWorld::home(), new AggregateVersion(2), $head);
}

it('plans the release of the revision for the entry\'s type, and nothing when it is the released revision', function (): void {
    $planner = new VariantReleasePlanner;
    $unreleased = new StoredHead(new AggregateVersion(3), new RevisionNumber(2), new RevisionNumber(2), null);
    $released = new StoredHead(new AggregateVersion(4), new RevisionNumber(2), new RevisionNumber(3), new RevisionNumber(3));

    expect($planner->plan(plannedEntry($unreleased), new RevisionNumber(2)))
        ->toEqual(new Plan(new VariantReleased(EntryActionWorld::entry(), EntryActionWorld::type(), VariantKey::shared(), new RevisionNumber(2))))
        ->and($planner->plan(plannedEntry($released), new RevisionNumber(2))->isEmpty())->toBeFalse()
        ->and($planner->plan(plannedEntry($released), new RevisionNumber(3))->isEmpty())->toBeTrue();
});

it('refuses to plan for a variant or an entry read as absent', function (): void {
    $action = new ReleaseVariantAction(new FakeEntryReader, new VariantReleasePlanner);
    $command = new ReleaseVariant(EntryActionWorld::entry(), RevisionNumber::first(), new AggregateVersion(1));

    expect(static fn (): Plan => new VariantReleasePlanner()->plan(plannedEntry(null), RevisionNumber::first()))
        ->toThrow(LogicException::class, 'whose shared variant was read as absent')
        ->and(static fn (): Plan => $action->plan($command, new ReleaseVariantAggregates(EntryActionWorld::entry(), null)))
        ->toThrow(LogicException::class, 'which was read as absent');
});

it('reads the entry and its shared variant at the versions it found, or as absent', function (): void {
    $variant = new VariantRef(EntryActionWorld::entry(), VariantKey::shared());
    $found = new ReleaseVariantAggregates(EntryActionWorld::entry(), plannedEntry(new StoredHead(new AggregateVersion(3), RevisionNumber::first(), RevisionNumber::first(), null)));
    $absent = new ReleaseVariantAggregates(EntryActionWorld::entry(), null);

    expect($found->versions()->reads)->toEqual([ReadVersion::at(EntryActionWorld::entry(), new AggregateVersion(2)), ReadVersion::at($variant, new AggregateVersion(3))])
        ->and($absent->versions()->reads)->toEqual([ReadVersion::absent(EntryActionWorld::entry()), ReadVersion::absent($variant)]);
});
