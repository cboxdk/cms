<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A contract's JSON Schema as cms:panel:types types it: the document's shape and the shape of each
 * definition of `$defs`, by its key, sorted.
 */
#[Internal]
final readonly class ShapeDocument
{
    /** @var array<string, JsonShape> */
    public array $definitions;

    /**
     * @param  array<string, JsonShape>  $definitions
     */
    public function __construct(
        public JsonShape $root,
        array $definitions = [],
    ) {
        ksort($definitions, SORT_STRING);
        $this->definitions = $definitions;
    }
}
