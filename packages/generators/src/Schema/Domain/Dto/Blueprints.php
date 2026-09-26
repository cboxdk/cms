<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Every definition read from a set of schema roots, each list in the sorted order of the files
 * they came from.
 */
#[Internal]
final readonly class Blueprints
{
    /**
     * @param  list<TypeBlueprint>  $types
     * @param  list<ExtensionBlueprint>  $extensions
     */
    public function __construct(
        public array $types,
        public array $extensions,
    ) {}
}
