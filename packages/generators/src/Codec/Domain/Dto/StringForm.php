<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The form of the string of an id or a value object, as its JSON Schema describes it (GUARDRAILS
 * 2.2): a pattern in the dialect of JSON Schema, which is ECMA-262's, and a length in characters.
 * The bound class's constructor is what checks it in PHP, so the PHP codec leaves it to the class;
 * the TypeScript validator, which has no class, checks it itself. With caseless, upper and lower
 * case letters write one value, as for an entry's id, which the class holds in lower case, so the
 * TypeScript validator compares the items of a distinct list in lower case.
 */
#[Internal]
final readonly class StringForm
{
    public function __construct(
        public ?string $pattern = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public bool $caseless = false,
    ) {}
}
