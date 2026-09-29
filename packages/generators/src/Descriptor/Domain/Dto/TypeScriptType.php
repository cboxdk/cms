<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The TypeScript type of a field's value (PRD 11.12), such as `string` or `'a' | 'b'`, and whether
 * the value may be null.
 */
#[Internal]
final readonly class TypeScriptType
{
    public function __construct(
        public string $type,
        public bool $nullable = false,
    ) {}

    public function withNullable(bool $nullable): self
    {
        return new self($this->type, $nullable);
    }

    /**
     * The type with `| null` when the value may be null.
     */
    public function declaration(): string
    {
        return $this->nullable ? $this->type.' | null' : $this->type;
    }
}
