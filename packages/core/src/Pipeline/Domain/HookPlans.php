<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Hooks\FieldChange;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RefusedChange;

/**
 * What hooks see of a pending plan, and how their changes enter it (GUARDRAILS 2.4, PRD 6.2
 * phases 2 to 5, 6.3, invariant 12).
 *
 * view() filters every revision's fields to the call's classification access, with each field's
 * classification from the TypeCatalog: a field above the access, or one the type does not declare,
 * is left out, so a hook sees only what the actor may read. apply() takes a transform hook's
 * changes in order and refuses the first that names a variant the plan writes no revision of, or
 * writes more than one of, a field the revision's type does not declare, or a field above the
 * access. A change sets one top-level field of one revision; the rest of the plan, its order and
 * its sub-plans stay as they were, so a hook cannot touch the actor, grants, classification or
 * another aggregate.
 *
 * A hook of an addon may read less than the actor, as its manifest says (PRD 13.1, invariant 21).
 * Given that lower classification, view() leaves out and apply() refuses every field above it, so
 * the kernel never hands an addon a field it may not read and the addon never changes one.
 */
#[Internal]
final readonly class HookPlans
{
    public function __construct(private TypeCatalog $types) {}

    /**
     * @param  ClassificationAccess|null  $readable  the classification the hook may read, when it is lower than the access; null for the access
     */
    public function view(CommandName $command, int $version, AccessContext $access, Plan $plan, ?ClassificationAccess $readable = null): PlanView
    {
        $classification = $readable ?? $access->classificationAccess;

        return new PlanView(
            $command,
            $version,
            $access->principal,
            $classification,
            ...array_map(
                fn (Mutation $mutation): Mutation => $mutation instanceof RevisionCreated ? $this->visible($mutation, $classification) : $mutation,
                $plan->mutations(),
            ),
        );
    }

    /**
     * The plan with every change applied in order, or the first change the kernel refuses.
     *
     * @param  ClassificationAccess|null  $readable  the classification the hook may read, when it is lower than the access; null for the access
     */
    public function apply(Plan $plan, FieldChanges $changes, AccessContext $access, ?ClassificationAccess $readable = null): Plan|RefusedChange
    {
        foreach ($changes->changes as $change) {
            $changed = $this->applyOne($plan, $change, $readable ?? $access->classificationAccess);

            if ($changed instanceof RefusedChange) {
                return $changed;
            }

            $plan = $changed;
        }

        return $plan;
    }

    private function applyOne(Plan $plan, FieldChange $change, ClassificationAccess $access): Plan|RefusedChange
    {
        $targets = [];

        foreach ($plan->mutations() as $mutation) {
            if ($mutation instanceof RevisionCreated && $change->variant->equals(new VariantRef($mutation->entry, $mutation->variant))) {
                $targets[] = $mutation;
            }
        }

        if (count($targets) !== 1) {
            return new RefusedChange($change, $targets === []
                ? sprintf('The plan writes no revision of the variant "%s", so a hook cannot change it.', $change->variant->aggregateKey())
                : sprintf('The plan writes %d revisions of the variant "%s", so a change of it is ambiguous.', count($targets), $change->variant->aggregateKey()));
        }

        $target = $targets[0];

        $field = $this->types->find($target->type)?->field($change->namespace, $change->handle);

        if (! $field instanceof FieldDefinition) {
            return new RefusedChange($change, sprintf('The type %s declares no field "%s".', $target->type->toString(), $change->address()));
        }

        if (! $access->allows($field->classification)) {
            return new RefusedChange($change, sprintf(
                'The field "%s" is classified %s, above the %s classification the actor may read.',
                $change->address(),
                $field->classification->value,
                $access->value,
            ));
        }

        return $this->rewrite($plan, $target, $change);
    }

    private function rewrite(Plan $plan, RevisionCreated $target, FieldChange $change): Plan
    {
        return new Plan(...array_map(
            fn (Mutation|Plan $step): Mutation|Plan => match (true) {
                $step instanceof Plan => $this->rewrite($step, $target, $change),
                $step === $target => $this->withFields($target, $this->set($target->fields, $change)),
                default => $step,
            },
            $plan->steps,
        ));
    }

    private function set(FieldValues $fields, FieldChange $change): FieldValues
    {
        if (! $change->namespace instanceof FieldNamespace) {
            return new FieldValues($this->put($fields->own, $change), ...$fields->extensions);
        }

        $namespace = $change->namespace;

        return new FieldValues(
            $fields->own,
            ...array_filter($fields->extensions, static fn (ExtensionFields $extension): bool => ! $extension->namespace->equals($namespace)),
            ...[new ExtensionFields($namespace, $this->put($fields->extension($namespace) ?? new FieldMap, $change))],
        );
    }

    private function put(FieldMap $map, FieldChange $change): FieldMap
    {
        return new FieldMap(
            ...array_filter($map->fields, static fn (NamedValue $field): bool => ! $field->handle->equals($change->handle)),
            ...[new NamedValue($change->handle, $change->value)],
        );
    }

    private function visible(RevisionCreated $revision, ClassificationAccess $access): RevisionCreated
    {
        $type = $this->types->find($revision->type);
        $extensions = [];

        foreach ($revision->fields->extensions as $extension) {
            $fields = $this->allowed($type, $extension->namespace, $extension->fields, $access);

            if (! $fields->isEmpty()) {
                $extensions[] = new ExtensionFields($extension->namespace, $fields);
            }
        }

        return $this->withFields($revision, new FieldValues($this->allowed($type, null, $revision->fields->own, $access), ...$extensions));
    }

    private function allowed(?TypeDefinition $type, ?FieldNamespace $namespace, FieldMap $fields, ClassificationAccess $access): FieldMap
    {
        return new FieldMap(...array_filter(
            $fields->fields,
            static function (NamedValue $field) use ($type, $namespace, $access): bool {
                $definition = $type?->field($namespace, $field->handle);

                return $definition instanceof FieldDefinition && $access->allows($definition->classification);
            },
        ));
    }

    private function withFields(RevisionCreated $revision, FieldValues $fields): RevisionCreated
    {
        return new RevisionCreated($revision->entry, $revision->type, $revision->variant, $revision->revision, $fields);
    }
}
