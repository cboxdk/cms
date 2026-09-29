<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The name of a constant of the module, such as the rule of a nested object.
 */
#[Internal]
final readonly class Reference implements Literal
{
    public function __construct(public string $name) {}

    public function flat(): string
    {
        return $this->name;
    }
}
