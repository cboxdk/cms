<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Core\Entries\Actions\ReleaseVariantAction;
use Cbox\Cms\Core\Entries\Adapter\PostgresRevisionContents;
use Cbox\Cms\Core\Entries\Adapter\VariantReleasedWriter;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;

/*
 * variant.release is wired into the kernel (PRD 5.6, 6.2, 13.2): cms:build registers its action
 * under variant.release version 1 on every surface, the container gives the pipeline that action
 * and the Postgres contents of a revision to validate a release against, and the commit finds the
 * writer of VariantReleased.
 */

const RELEASE_REGISTRATION_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000004e2';

it('registers variant.release version 1 on every surface', function (): void {
    $entry = app(CompiledRegistry::class)->actionFor(ReleaseVariant::class);

    expect($entry?->class)->toBe(ReleaseVariantAction::class)
        ->and($entry?->command->value)->toBe('variant.release')
        ->and($entry?->commandVersion)->toBe(1)
        ->and($entry?->package)->toBe('cboxdk/cms')
        ->and($entry?->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]);
});

it('gives the pipeline the release action, the Postgres revision contents and the writer of a release', function (): void {
    $entry = EntryId::fromString(RELEASE_REGISTRATION_ENTRY);
    $binding = app(WriteActions::class)->for(new ReleaseVariant($entry, RevisionNumber::first(), new AggregateVersion(1)));

    expect($binding->action)->toBeInstanceOf(ReleaseVariantAction::class)
        ->and($binding->command->value)->toBe('variant.release')
        ->and(app(RevisionContents::class))->toBeInstanceOf(PostgresRevisionContents::class)
        ->and(app(MutationWriters::class)->for(new VariantReleased($entry, TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000004d2'), VariantKey::shared(), RevisionNumber::first())))
        ->toBeInstanceOf(VariantReleasedWriter::class);
});
