<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Publishing;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementClosed;
use Cbox\Cms\Contracts\Plans\Mutations\VariantUnreleased;
use Cbox\Cms\Core\Entries\Adapter\VariantUnreleasedWriter;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Placements\Adapter\PlacementClosedWriter;
use Cbox\Cms\Core\Publishing\Actions\PublishEntryAction;
use Cbox\Cms\Core\Publishing\Actions\UnpublishEntryAction;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/*
 * entry.publish and entry.unpublish are wired into the kernel (PRD 6.4, 13.2): cms:build registers
 * their actions under version 1 on every surface, the container gives the pipeline those actions,
 * and the commit finds the writers of VariantUnreleased and PlacementClosed.
 */

const REGISTRATION_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000007e1';

const REGISTRATION_PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-0000000007c1';

it('registers entry.publish and entry.unpublish version 1 on every surface', function (): void {
    $registry = app(CompiledRegistry::class);
    $publish = $registry->actionFor(PublishEntry::class);
    $unpublish = $registry->actionFor(UnpublishEntry::class);
    $surfaces = [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli];

    expect([$publish?->class, $publish?->command->value, $publish?->commandVersion, $publish?->surfaces])
        ->toBe([PublishEntryAction::class, 'entry.publish', 1, $surfaces])
        ->and([$unpublish?->class, $unpublish?->command->value, $unpublish?->commandVersion, $unpublish?->surfaces])
        ->toBe([UnpublishEntryAction::class, 'entry.unpublish', 1, $surfaces]);
});

it('gives the pipeline the actions and the commit the writers of an unrelease and a closed placement', function (): void {
    $entry = EntryId::fromString(REGISTRATION_ENTRY);
    $placement = PlacementId::fromString(REGISTRATION_PLACEMENT);
    $actions = app(WriteActions::class);
    $writers = app(MutationWriters::class);

    expect($actions->for(new PublishEntry($entry, new AggregateVersion(1), RevisionNumber::first(), $placement, new AggregateVersion(1), new Locale('da')))->action)->toBeInstanceOf(PublishEntryAction::class)
        ->and($actions->for(new UnpublishEntry($entry, new AggregateVersion(1)))->action)->toBeInstanceOf(UnpublishEntryAction::class)
        ->and($writers->for(new VariantUnreleased($entry, VariantKey::shared(), RevisionNumber::first())))->toBeInstanceOf(VariantUnreleasedWriter::class)
        ->and($writers->for(new PlacementClosed($placement, new Locale('da'))))->toBeInstanceOf(PlacementClosedWriter::class);
});
