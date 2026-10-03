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
use Cbox\Cms\Contracts\Hooks\ReleasedRevision;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\ClassifiedMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ClosedValue;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RefusedChange;

/**
 * What hooks see of a pending plan, and how their changes enter it (GUARDRAILS 2.4, PRD 6.2
 * phases 2 to 5, 6.3, invariant 12).
 *
 * view() filters every revision's fields to the call's classification access, with each field's
 * classification from the TypeCatalog: a field above the access, or one the type does not declare,
 * is left out, so a hook sees only what the actor may read. A ClassifiedMutation whose
 * classification the access does not allow, such as the profile of an actor actor.register
 * creates, is shown without its classified values (withoutClassified()). The revisions the plan's releases make
 * public, which the pipeline read before the hooks, are filtered the same way. apply() takes a transform hook's
 * changes in order and refuses the first that names a variant the plan writes no revision of, or
 * writes more than one of, a field the revision's type does not declare, or a field above the
 * access. A change sets one top-level field of one revision; the rest of the plan, its order and
 * its sub-plans stay as they were, so a hook cannot touch the actor, grants, classification or
 * another aggregate.
 *
 * A hook of an addon may read less than the actor, as its manifest says (PRD 13.1, invariant 21).
 * Given that lower classification, view() leaves out and apply() refuses every field above it, so
 * the kernel never hands an addon a field it may not read and the addon never changes one.
 *
 * For a call an agent issues, view() also leaves out every field, and every nested field of a
 * group, whose blueprint closes it to agents, by the read rule every read applies
 * (TypeDefinition::readable()), and apply() refuses a change that sets one (WritableFields), as the
 * kernel refuses the agent's own value for it (PRD 2.31).
 */
#[Internal]
final readonly class HookPlans
{
    public function __construct(private TypeCatalog $types) {}

    /**
     * @param  ClassificationAccess|null  $readable  the classification the hook may read, when it is lower than the access; null for the access
     * @param  list<ReleasedRevision>  $releases  the revisions the plan's releases make public, with every field they hold
     * @param  bool  $agent  whether an agent issues the call, so the fields closed to agents are left out too
     */
    public function view(CommandName $command, int $version, AccessContext $access, Plan $plan, ?ClassificationAccess $readable = null, array $releases = [], bool $agent = false): PlanView
    {
        $classification = $readable ?? $access->classificationAccess;

        return new PlanView(
            $command,
            $version,
            $access->principal,
            $classification,
            ...array_map(
                fn (Mutation $mutation): Mutation => match (true) {
                    $mutation instanceof RevisionCreated => $this->visible($mutation, $classification, $agent),
                    $mutation instanceof ClassifiedMutation && ! $classification->allows($mutation->classification()) => $mutation->withoutClassified(),
                    default => $mutation,
                },
                $plan->mutations(),
            ),
        )->withReleases(...array_map(
            fn (ReleasedRevision $released): ReleasedRevision => new ReleasedRevision($released->release, $this->filtered($released->release->type, $released->fields, $classification, $agent)),
            $releases,
        ));
    }

    /**
     * The plan with every change applied in order, or the first change the kernel refuses.
     *
     * @param  ClassificationAccess|null  $readable  the classification the hook may read, when it is lower than the access; null for the access
     * @param  bool  $agent  whether an agent issues the call, so a field closed to agents is refused too
     */
    public function apply(Plan $plan, FieldChanges $changes, AccessContext $access, ?ClassificationAccess $readable = null, bool $agent = false): Plan|RefusedChange
    {
        foreach ($changes->changes as $change) {
            $changed = $this->applyOne($plan, $change, $readable ?? $access->classificationAccess, $agent);

            if ($changed instanceof RefusedChange) {
                return $changed;
            }

            $plan = $changed;
        }

        return $plan;
    }

    private function applyOne(Plan $plan, FieldChange $change, ClassificationAccess $access, bool $agent): Plan|RefusedChange
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

        $closed = $field->readableBy($access, $agent) ? WritableFields::closedIn($field, $change->value, $access, $agent, new FieldPath($change->handle->value)) : [new ClosedValue(new FieldPath($change->handle->value), $field)];

        if ($closed !== []) {
            return new RefusedChange($change, sprintf(
                'The change sets %s of the field "%s", closed to agents (agents: false in its blueprint), and an agent issues the call.',
                implode(', ', array_map(static fn (ClosedValue $value): string => $value->path->toString(), $closed)),
                $change->address(),
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

    private function visible(RevisionCreated $revision, ClassificationAccess $access, bool $agent): RevisionCreated
    {
        return $this->withFields($revision, $this->filtered($revision->type, $revision->fields, $access, $agent));
    }

    /**
     * The fields a reader with the access may read, of the owner and of every extension, by the
     * type's own read rule (TypeDefinition::readable()): nothing above the access, nothing the type
     * does not declare, and for an agent nothing closed to agents.
     */
    private function filtered(TypeId $typeId, FieldValues $values, ClassificationAccess $access, bool $agent): FieldValues
    {
        $type = $this->types->find($typeId);

        return $type instanceof TypeDefinition ? $type->readable($values, $access, $agent) : new FieldValues(new FieldMap);
    }

    private function withFields(RevisionCreated $revision, FieldValues $fields): RevisionCreated
    {
        return new RevisionCreated($revision->entry, $revision->type, $revision->variant, $revision->revision, $fields);
    }
}
