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
 *
 * A plan that releases a revision holds only the release, which names the revision; the view also
 * holds each released revision with its fields (withReleases(), releases()), read by the kernel
 * before the hooks run and filtered like a created revision, so a validate hook can require a field
 * at the release (PRD 11.12, invariant 36). A release whose revision the kernel cannot read, one
 * the variant does not have or one written under another schema version, is not among them; the
 * kernel rejects that release after the validate hooks.
 */
#[Experimental]
final readonly class PlanView
{
    /** @var list<Mutation> */
    public array $mutations;

    /** @var list<ReleasedRevision> */
    public array $released;

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
        $this->released = $this->releaseList();
    }

    /**
     * The same view with the revisions the plan's releases make public, as the kernel read them.
     */
    public function withReleases(ReleasedRevision ...$released): self
    {
        return clone ($this, ['released' => $this->releaseList(...$released)]);
    }

    /**
     * The revisions the plan releases, with their fields, in the order of the releases.
     *
     * @return list<ReleasedRevision>
     */
    public function releases(): array
    {
        return $this->released;
    }

    /**
     * The revision the plan releases for the variant, or null when it releases none.
     */
    public function release(VariantRef $variant): ?ReleasedRevision
    {
        return array_find(
            $this->released,
            static fn (ReleasedRevision $released): bool => $variant->equals($released->variant()),
        );
    }

    /**
     * The released revisions of a new view: none until the kernel adds them with withReleases().
     *
     * @return list<ReleasedRevision>
     */
    private function releaseList(ReleasedRevision ...$released): array
    {
        return array_values($released);
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
