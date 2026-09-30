<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use LogicException;

/**
 * The kernel's planner of a release (GUARDRAILS 2.1, PRD 5.6, 6.4): the plan that makes a revision
 * of an entry's shared variant its released revision, for the entry's own type. variant.release
 * plans with it, and a composite command such as entry.publish composes its plan with the plans of
 * other planners, so one changeset releases and does the rest.
 *
 * The plan is empty when the revision is the variant's released revision already, so a release
 * that would change nothing commits nothing. Everything else a release needs is the kernel's to
 * check at the commit's phases: that the type has a revision to release, that the revision exists
 * and validates against its own schema version at the release stage (invariant 5), and that the
 * caller may change public visibility (invariant 18).
 */
#[Internal]
final readonly class VariantReleasePlanner
{
    /**
     * @throws LogicException when the entry's shared variant has no head, which the kernel's check of the expected version rules out
     */
    public function plan(StoredEntry $entry, RevisionNumber $revision): Plan
    {
        $head = $entry->head;

        if (! $head instanceof StoredHead) {
            throw new LogicException(sprintf(
                'A release of the entry %s was planned, whose shared variant was read as absent; the kernel rejects such a call with version_conflict before it plans.',
                $entry->id->toString(),
            ));
        }

        if ($head->released instanceof RevisionNumber && $head->released->equals($revision)) {
            return new Plan;
        }

        return new Plan(new VariantReleased($entry->id, $entry->type, VariantKey::shared(), $revision));
    }
}
