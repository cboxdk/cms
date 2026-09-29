<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;

/**
 * The typed, read-only view of the pending plan a hook receives (GUARDRAILS 2.4, PRD 6.3): the
 * command by name and version, the principal it runs for, and the plan's mutations in the order
 * the kernel applies them, sub-plans flattened.
 *
 * The view is filtered to the classification access of the call: every revision's fields hold only
 * the fields whose classification the access allows, so a hook never sees, and cannot change, a
 * field the actor may not read. A field the view leaves out is absent from its FieldValues, as if
 * the revision did not set it. An addon's hook may be narrowed further to its manifest's
 * capabilities.
 */
#[Experimental]
final readonly class PlanView
{
    /** @var list<Mutation> */
    public array $mutations;

    /**
     * @throws InvalidHookResult when the version is below 1
     */
    public function __construct(
        public CommandName $command,
        public int $version,
        public Principal $principal,
        public ClassificationAccess $classificationAccess,
        Mutation ...$mutations,
    ) {
        if ($version < 1) {
            throw InvalidHookResult::version($version);
        }

        $this->mutations = array_values($mutations);
    }

    /**
     * The revisions the plan creates, in order.
     *
     * @return list<RevisionCreated>
     */
    public function revisions(): array
    {
        return array_values(array_filter(
            $this->mutations,
            static fn (Mutation $mutation): bool => $mutation instanceof RevisionCreated,
        ));
    }

    /**
     * The revision the plan creates for the variant, or null when it creates none.
     */
    public function revision(VariantRef $variant): ?RevisionCreated
    {
        return array_find(
            $this->revisions(),
            static fn (RevisionCreated $revision): bool => $variant->equals(new VariantRef($revision->entry, $revision->variant)),
        );
    }
}
