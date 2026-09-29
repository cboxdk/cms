<?php

declare(strict_types=1);

namespace Examples\Postgres\Events;

use Cbox\Cms\Contracts\Ids\Identifier;
use InvalidArgumentException;

/**
 * The id of an addon's aggregate, a warehouse. As an Identifier it can be carried by an event.
 */
final readonly class WarehouseId implements Identifier
{
    public function __construct(public string $value)
    {
        if (preg_match('/\Awh-[0-9]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('A warehouse id is "wh-" and digits.');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
