<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Mutations\VariantDerived;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateVariantFrom;
use Cbox\Cms\Core\Entries\Domain\Dto\CreateVariantFromAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredVariants;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RevisionContent;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use LogicException;
use Override;

/**
 * The write action of variant.create_from (PRD 6.2, 9.2, 10.3), exposed on every surface.
 * resolve() reads the entry with the heads of the new variant and of the source, the entry's type,
 * and the source revision's content; plan() writes the new variant's first revision as a copy of
 * the source revision's fields, moves its head to it and records the derivation translated_from,
 * all in one changeset. It works for any type with localization variants from its schema: the
 * kernel validates the copy against the fields a language's variant holds at the write stage.
 *
 * refusals() refuses, with validation_failed, a type without languages, a new variant or a source
 * that is the shared variant, which holds no language's fields, one variant as both, a source
 * revision the variant does not have, and one written under another schema version than the
 * type's, whose fields the type cannot read (upcasters come with B3). An entry or a source at
 * another version than the caller read, and a new variant the entry has already, are the kernel's
 * version_conflict.
 *
 * @implements WriteAction<CreateVariantFrom, CreateVariantFromAggregates>
 * @implements RefusesCommand<CreateVariantFrom, CreateVariantFromAggregates>
 */
#[Action(handles: CreateVariantFrom::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class CreateVariantFromAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private EntryReader $entries,
        private TypeCatalog $types,
        private RevisionContents $revisions,
    ) {}

    /**
     * @param  CreateVariantFrom  $command
     */
    #[Override]
    public function resolve(Command $command): CreateVariantFromAggregates
    {
        $stored = $this->entries->variants($command->entry, $command->variant->equals($command->source) ? [$command->source] : [$command->variant, $command->source]);

        if (! $stored instanceof StoredVariants) {
            return new CreateVariantFromAggregates($command->entry, $command->target(), $command->origin(), null);
        }

        $type = $this->types->find($stored->type);

        return new CreateVariantFromAggregates(
            $command->entry,
            $command->target(),
            $command->origin(),
            $stored,
            $type,
            $type instanceof TypeDefinition && $stored->head($command->source) instanceof StoredHead
                ? $this->revisions->find($command->entry, $command->source, $command->revision, $type)
                : null,
        );
    }

    /**
     * @param  CreateVariantFrom  $command
     * @param  CreateVariantFromAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $type = $aggregates->type;

        if (! $type instanceof TypeDefinition) {
            return [$this->invalid('entry', sprintf('The entry %s has no type of this installation.', $command->entry->toString()))];
        }

        if ($type->capabilities->localization !== Localization::Variants) {
            return [$this->invalid('variant', sprintf(
                'The type %s has localization none, whose entries have the shared variant alone (PRD 10.1); variant.create_from creates a language\'s variant of a type with localization variants.',
                $type->name->value,
            ))];
        }

        $errors = [];

        if ($command->variant->isShared()) {
            $errors[] = $this->invalid('variant', 'variant.create_from creates a language\'s variant; every entry has its shared variant from its creation.');
        }

        if ($command->source->isShared()) {
            $errors[] = $this->invalid('source', 'A translation is made from a language\'s variant; the shared variant holds the fields that do not vary by language, which the new variant does not hold.');
        }

        if ($command->variant->equals($command->source)) {
            $errors[] = $this->invalid('variant', sprintf('The variant %s cannot be created from itself.', $command->variant->value));
        }

        if ($errors !== []) {
            return $errors;
        }

        $content = $aggregates->content;

        if (! $content instanceof RevisionContent) {
            return [$this->invalid('revision', sprintf('The variant %s of the entry %s has no revision %d.', $command->source->value, $command->entry->toString(), $command->revision->value))];
        }

        if (! $content->fields instanceof FieldValues) {
            return [$this->invalid('revision', sprintf(
                'Revision %d of the variant %s was written under schema version %d of the type %s, which is at version %d now, and a revision is copied only at its type\'s version.',
                $command->revision->value,
                $command->source->value,
                $content->schemaVersion,
                $type->name->value,
                $type->version,
            ))];
        }

        return [];
    }

    /**
     * @param  CreateVariantFrom  $command
     * @param  CreateVariantFromAggregates  $aggregates
     *
     * @throws LogicException when the entry, its type or the source revision's fields were read as absent, which refusals() rules out first
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $fields = $aggregates->content?->fields;

        if (! $aggregates->stored instanceof StoredVariants || ! $fields instanceof FieldValues) {
            throw new LogicException(sprintf('variant.create_from planned the variant %s of the entry %s without the source revision\'s fields; refusals() refuses such a call first.', $command->variant->value, $command->entry->toString()));
        }

        $first = RevisionNumber::first();

        return new Plan(
            new RevisionCreated($command->entry, $aggregates->stored->type, $command->variant, $first, $fields),
            new HeadMoved($command->entry, $command->variant, null, $first),
            new VariantDerived($command->entry, $command->variant, $first, $command->source, $command->revision),
        );
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
