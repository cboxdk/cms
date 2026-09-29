<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * The kernel cannot commit a changeset as it is wired or planned (PRD 6.2 phase 7): a mutation has
 * no MutationWriter, an aggregate the command read has no VersionLock, two writers or locks claim
 * the same class or kind, a writer returned an event about another aggregate or version, or the
 * plan has no mutation. It is a bug in the kernel's wiring or in an action, not a result, and it is
 * thrown before or inside the command transaction, which then rolls back. Nothing is committed.
 */
#[Internal]
final class UncommittableChangeset extends LogicException
{
    public static function noWriter(string $mutation): self
    {
        return new self(sprintf('No MutationWriter is registered for the mutation %s, so the kernel cannot write it. Register the writer of its command with the tag %s.', $mutation, MutationWriters::TAG));
    }

    public static function duplicateWriter(string $mutation): self
    {
        return new self(sprintf('Two MutationWriters are registered for the mutation %s. Register one writer per mutation class.', $mutation));
    }

    public static function noLock(string $aggregate): self
    {
        return new self(sprintf('No VersionLock is registered for the kind of the aggregate "%s", so the kernel cannot check its version. Register the lock of its kind with the tag %s.', $aggregate, VersionLocks::TAG));
    }

    public static function duplicateLock(string $kind): self
    {
        return new self(sprintf('Two VersionLocks are registered for the kind of aggregate "%s". Register one lock per kind.', $kind));
    }

    public static function foreignEvent(string $writer, string $event, string $aggregate, int $version): self
    {
        return new self(sprintf('The MutationWriter %s returned the event %s about another aggregate or version than its mutation\'s, "%s" at version %d. A writer returns only events about its mutation\'s aggregate at the changeset\'s version.', $writer, $event, $aggregate, $version));
    }

    public static function emptyPlan(string $command): self
    {
        return new self(sprintf('The plan of the command %s has no mutation, and a changeset changes at least one aggregate. A command that changes nothing ends before the commit.', $command));
    }
}
