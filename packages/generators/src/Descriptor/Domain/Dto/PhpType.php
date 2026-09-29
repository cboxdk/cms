<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The PHP type of a field's value (PRD 11.12): the native type a property declares, such as
 * `string` or `DateTimeImmutable` (a class without a leading backslash), the PHPDoc type PHPStan
 * reads, such as `int<0, 100>` or `list<'a'|'b'>`, and whether the value may be null.
 */
#[Internal]
final readonly class PhpType
{
    public function __construct(
        public string $native,
        public string $doc,
        public bool $nullable = false,
    ) {}

    public function withNullable(bool $nullable): self
    {
        return new self($this->native, $this->doc, $nullable);
    }

    /**
     * The PHPDoc type with `|null` when the value may be null.
     */
    public function docType(): string
    {
        return $this->nullable ? $this->doc.'|null' : $this->doc;
    }
}
