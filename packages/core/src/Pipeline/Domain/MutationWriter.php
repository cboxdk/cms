<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Events\Event;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;

/**
 * Writes one class of mutation of a plan in the commit (PRD 6.2 phase 7, 7.3): the rows the
 * mutation changes, with the aggregate at the version the context gives, and the events that tell
 * about it. The kernel registers one writer per mutation class (MutationWriters); each command
 * task adds the writers of its mutations.
 *
 * write() runs on the command transaction's connection, inside it, after the kernel has locked
 * and checked every aggregate the command read and written the changeset row, so a row it writes
 * can name the changeset. It never begins, ends or savepoints a transaction and never writes
 * anything but the mutation's own rows: the kernel writes the changeset, the audit, the events it
 * returns and the receipt. A failure throws, and the kernel rolls back everything.
 *
 * Every event it returns is about the mutation's aggregate at the context's version. The kernel
 * hands write() only mutations of the class writes() names.
 */
#[Internal]
interface MutationWriter
{
    /**
     * The class of mutation it writes.
     *
     * @return class-string<Mutation>
     */
    public function writes(): string;

    /**
     * @return list<Event>
     */
    public function write(Mutation $mutation, MutationContext $context): array;
}
