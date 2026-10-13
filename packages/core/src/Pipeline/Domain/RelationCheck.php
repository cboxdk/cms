<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Pipeline\Domain\Dto\EntryTypes;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RelationReference;

/**
 * The kernel's check of the relations a plan writes (PRD 5.11, 6.2 phase 5): every target of a
 * relation field in a revision the plan creates is an entry that exists and that the actor can
 * read, validation_relation_target_missing otherwise, and its type is one the field allows,
 * validation_relation_target_type otherwise, each at the field's path. An entry the plan itself
 * creates counts as existing with its type, so one changeset can create entries that name each
 * other. The targets of every revision are read through RelationTargets in one statement, whatever
 * their number, and only when there is a target the plan does not create. A field whose value the
 * validators already refused at its path is not checked again.
 */
#[Internal]
final readonly class RelationCheck
{
    public function __construct(
        private FieldValidation $fields,
        private RelationTargets $targets,
        private TypeCatalog $types,
    ) {}

    /**
     * @param  FieldPath  $at  the path of a revision's fields in the command, such as `fields`
     * @param  list<CatalogError>  $refused  the errors found already
     * @return list<CatalogError>
     */
    public function errors(Plan $plan, FieldPath $at, array $refused): array
    {
        $created = new EntryTypes;
        $references = [];
        $paths = array_map(static fn (CatalogError $error): ?string => $error->path?->toString(), $refused);

        foreach ($plan->mutations() as $mutation) {
            if ($mutation instanceof EntryCreated) {
                $created = $created->with($mutation->entry, $mutation->type);
            }

            $type = $mutation instanceof RevisionCreated ? $this->types->find($mutation->type) : null;

            if ($mutation instanceof RevisionCreated && $type instanceof TypeDefinition) {
                foreach ($this->fields->references($type, $mutation->variant, $mutation->fields, $at) as $reference) {
                    if (! in_array($reference->path->toString(), $paths, true)) {
                        $references[] = $reference;
                    }
                }
            }
        }

        if ($references === []) {
            return [];
        }

        $unread = [];

        foreach ($references as $reference) {
            if (! $created->of($reference->entry) instanceof TypeId) {
                $unread[$reference->entry->toString()] = $reference->entry;
            }
        }

        $found = $unread === [] ? new EntryTypes : $this->targets->types(array_values($unread));
        $errors = [];

        foreach ($references as $reference) {
            $error = $this->error($reference, $created, $found);

            if ($error instanceof CatalogError) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function error(RelationReference $reference, EntryTypes $created, EntryTypes $found): ?CatalogError
    {
        $type = $created->of($reference->entry) ?? $found->of($reference->entry);
        $item = $reference->item === null ? '' : sprintf(' as item %d', $reference->item);

        if (! $type instanceof TypeId) {
            return new CatalogError(ErrorCode::ValidationRelationTargetMissing, $reference->path, sprintf(
                'names the entry %s%s, which does not exist or which the actor cannot read.',
                $reference->entry->toString(),
                $item,
            ));
        }

        $definition = $this->types->find($type);

        if ($definition instanceof TypeDefinition && $reference->allows($definition->name)) {
            return null;
        }

        return new CatalogError(ErrorCode::ValidationRelationTargetType, $reference->path, sprintf(
            'names the entry %s%s of the type %s, and the field allows entries of %s only.',
            $reference->entry->toString(),
            $item,
            $definition instanceof TypeDefinition ? $definition->name->value : $type->toString(),
            implode(', ', array_map(static fn (TypeName $name): string => $name->value, $reference->targets)),
        ));
    }
}
