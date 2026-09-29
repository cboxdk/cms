<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain\TypeScript;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A property of an object literal: its key and its value.
 */
#[Internal]
final readonly class Property
{
    private const string IDENTIFIER = '/\A[A-Za-z_$][A-Za-z0-9_$]*\z/';

    public function __construct(
        public string $name,
        public Literal $value,
    ) {}

    /**
     * The key as Prettier writes it: bare when it is an identifier, and quoted otherwise.
     */
    public function key(): string
    {
        return preg_match(self::IDENTIFIER, $this->name) === 1 ? $this->name : new StringLiteral($this->name)->flat();
    }
}
