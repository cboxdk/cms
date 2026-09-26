<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What the doctor's role owns and may create in the current database (PRD 4.2).
 */
#[Internal]
final readonly class DdlPrivileges
{
    /**
     * @param  list<string>  $ownedRelations  schema-qualified tables, views and sequences the role owns, at most a few
     * @param  int  $ownedCount  how many relations the role owns in all
     * @param  list<string>  $schemasWithCreate  the schemas outside the system schemas where the role may create objects
     */
    public function __construct(
        public string $role,
        public string $database,
        public array $ownedRelations,
        public int $ownedCount,
        public bool $createOnDatabase,
        public array $schemasWithCreate,
    ) {}
}
