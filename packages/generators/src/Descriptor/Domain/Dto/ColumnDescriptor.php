<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The column of a top-level field in its type table (PRD 11.6, 11.12): the name, the handle or
 * `ext__<namespace>__<handle>`; the Postgres type; whether it is NOT NULL; and the CHECK
 * expressions over it; and the kernel table whose `id` it references with a foreign key ON DELETE
 * RESTRICT, such as `entries` for a to-one relation (PRD 11.6), or null. An encrypted field is
 * `bytea` of ciphertext without checks or a reference (PRD 12.2).
 */
#[Internal]
final readonly class ColumnDescriptor
{
    /**
     * @param  list<string>  $checks
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $notNull,
        public array $checks,
        public ?string $references = null,
    ) {}
}
