<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PointId;

/**
 * The JSON Schemas cms:build checks the panel's contributions against (PRD 13.4), as SchemaNodes:
 * each command's document and each query's document by `<name>@<version>`, from their generated
 * codecs, and each panel point's props by its id, from the point's props schema. A contract
 * without a schema here has none the build can read.
 */
#[Experimental]
final readonly class ContractShapes
{
    /**
     * @param  array<string, SchemaNode>  $commands  by `<name>@<version>`
     * @param  array<string, SchemaNode>  $queries  by `<name>@<version>`
     * @param  array<string, SchemaNode>  $points  by point id
     */
    public function __construct(
        public array $commands = [],
        public array $queries = [],
        public array $points = [],
    ) {}

    public function command(CommandName $name, int $version): ?SchemaNode
    {
        return $this->commands[$name->value.'@'.$version] ?? null;
    }

    public function query(CommandName $name, int $version): ?SchemaNode
    {
        return $this->queries[$name->value.'@'.$version] ?? null;
    }

    public function point(PointId $point): ?SchemaNode
    {
        return $this->points[$point->toString()] ?? null;
    }
}
