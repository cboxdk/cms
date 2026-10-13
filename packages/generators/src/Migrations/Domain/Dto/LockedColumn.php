<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A field's column in a type's schema lock (PRD 11.6): its name, Postgres type, NOT NULL, CHECK
 * expressions, whether it has an index and the table its foreign key references, as the field's
 * descriptor gave them, and the step of the
 * lock whose migration added it. The system columns (`cms_*`) are not in the lock; every type table
 * has them.
 */
#[Internal]
final readonly class LockedColumn
{
    /**
     * @param  list<string>  $checks  the CHECK expressions over the quoted column
     * @param  bool  $indexed  whether the field is filterable or sortable, so its column has an index
     *                         that ends with the entry id
     * @param  int  $step  the step of the schema lock that added the column, from 1
     * @param  ?string  $references  the kernel table whose `id` the column references with a foreign key, or null
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $notNull,
        public array $checks,
        public bool $indexed,
        public int $step,
        public ?string $references = null,
    ) {}

    /**
     * Whether the column is the same as another in everything but the step that added it.
     */
    public function sameShape(self $other): bool
    {
        return $this->name === $other->name
            && $this->type === $other->type
            && $this->notNull === $other->notNull
            && $this->checks === $other->checks
            && $this->indexed === $other->indexed
            && $this->references === $other->references;
    }
}
