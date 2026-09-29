<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use SensitiveParameter;

/**
 * A credential as the transport carried it, such as the bearer token of an Authorization header,
 * before it is verified (PRD 5.16). The value is a secret: it is kept out of stack traces and
 * var_dump(), and is read only with reveal().
 */
#[Experimental]
final readonly class TransportCredential
{
    public function __construct(#[SensitiveParameter] private string $value) {}

    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[secret]'];
    }
}
