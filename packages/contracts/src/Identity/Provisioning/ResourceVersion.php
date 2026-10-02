<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The version of a SCIM resource in the CMS (RFC 7644 3.14), from 1 when it is created and one
 * higher after each call or command that changes it, whatever made the change. SCIM sends it
 * back as a weak ETag in If-Match, and it is part of a change's idempotency key.
 */
#[Experimental]
final readonly class ResourceVersion
{
    /**
     * @throws InvalidIdentity when the value is below 1
     */
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw InvalidIdentity::signalValue('version of a SCIM resource', 'at least 1');
        }
    }

    public static function first(): self
    {
        return new self(1);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    /**
     * The weak entity tag of the version, as meta.version and the ETag header carry it.
     */
    public function etag(): string
    {
        return sprintf('W/"%d"', $this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
