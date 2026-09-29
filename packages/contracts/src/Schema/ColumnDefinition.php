<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The column of a top-level field in its type table (PRD 4.1, 11.6), as cms:generate compiled it
 * from the blueprint: the name, the field's handle or `ext__<namespace>__<handle>` for an extension
 * field; the Postgres type, such as `text`, `numeric(10, 2)` or `bytea` for ciphertext (PRD 12.2);
 * whether it is NOT NULL; and the CHECK expressions over it, each over the quoted column. The
 * kernel writes the type table from it without knowing the type (GUARDRAILS 2.4).
 */
#[Experimental]
final readonly class ColumnDefinition
{
    private const string NAME = '/\A[a-z][a-z0-9_]*\z/';

    /**
     * @param  list<string>  $checks
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $notNull,
        public array $checks,
    ) {
        if (preg_match(self::NAME, $name) !== 1 || strlen($name) > 63) {
            throw InvalidTypeDefinition::column($name);
        }

        if (trim($type) === '') {
            throw InvalidTypeDefinition::columnType($name);
        }
    }
}
