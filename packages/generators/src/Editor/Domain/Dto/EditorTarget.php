<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;

/**
 * What cms:schema:editor edits (blueprint decision 3): the blueprint files below the schema roots,
 * and the blueprint schema their editor line points at.
 */
#[Internal]
final readonly class EditorTarget
{
    /**
     * @param  non-empty-list<SchemaRoot>  $roots  the schema roots of cms.generators.roots
     * @param  string  $schema  the absolute, canonical path of blueprint.v1.json in the installed cboxdk/cms-contracts
     */
    public function __construct(
        public array $roots,
        public string $schema,
    ) {}
}
