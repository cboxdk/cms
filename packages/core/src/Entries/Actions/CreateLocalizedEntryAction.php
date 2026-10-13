<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateLocalizedEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\CreateLocalizedEntryAggregates;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Override;

/**
 * The write action of entry.create version 2 (PRD 5.4, 6.2, 10.1), exposed on every surface.
 * resolve() reads the entry with the heads of the variants the command creates, which it expects
 * to be absent, and the home node; plan() creates the entry, the first revision of the shared
 * variant and of each language's variant, and each variant's head on it, all in one changeset. It
 * works for any type from its schema: the kernel validates each variant's fields against the
 * fields that variant holds, refuses a language of a type with localization none, which has the
 * shared variant alone, and the writers store each revision and the type table's rows as the
 * type's capabilities say. A type with localization variants needs at least one language, so no
 * entry of it is left without one.
 *
 * @implements WriteAction<CreateLocalizedEntry, CreateLocalizedEntryAggregates>
 * @implements RefusesCommand<CreateLocalizedEntry, CreateLocalizedEntryAggregates>
 */
#[Action(handles: CreateLocalizedEntry::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class CreateLocalizedEntryAction implements RefusesCommand, WriteAction
{
    public const string LOCALES = 'locales';

    public function __construct(
        private EntryReader $entries,
        private TypeCatalog $types,
    ) {}

    /**
     * @param  CreateLocalizedEntry  $command
     */
    #[Override]
    public function resolve(Command $command): CreateLocalizedEntryAggregates
    {
        $variants = $command->variants();

        return new CreateLocalizedEntryAggregates(
            $command->entry,
            $variants,
            $this->entries->variants($command->entry, array_map(static fn (VariantRef $variant): VariantKey => $variant->variant, $variants)),
            $command->home,
            $this->entries->node($command->home),
            $this->types->find($command->type),
        );
    }

    /**
     * A type with localization variants without a language.
     *
     * @param  CreateLocalizedEntry  $command
     * @param  CreateLocalizedEntryAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $type = $aggregates->type;

        if ($type instanceof TypeDefinition && $type->capabilities->localization === Localization::Variants && $command->locales === []) {
            return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath(self::LOCALES), sprintf(
                'The type %s has localization variants, so an entry of it has a variant per language beside the shared one (PRD 10.1); entry.create gives at least one language, so no entry is left without one.',
                $type->name->value,
            ))];
        }

        return [];
    }

    /**
     * @param  CreateLocalizedEntry  $command
     * @param  CreateLocalizedEntryAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $first = RevisionNumber::first();
        $revisions = [new RevisionCreated($command->entry, $command->type, VariantKey::shared(), $first, $command->shared)];
        $heads = [new HeadMoved($command->entry, VariantKey::shared(), null, $first)];

        foreach ($command->locales as $locale) {
            $revisions[] = new RevisionCreated($command->entry, $command->type, $locale->variant(), $first, $locale->fields);
            $heads[] = new HeadMoved($command->entry, $locale->variant(), null, $first);
        }

        return new Plan(new EntryCreated($command->entry, $command->type, $command->home), ...$revisions, ...$heads);
    }
}
