<?php

declare(strict_types=1);

namespace Examples\Unit\Subscribers;

use Cbox\Cms\Contracts\Ids\Identifier;
use InvalidArgumentException;

/**
 * The id of the search addon's aggregate, a page. As an Identifier it can be carried by an event.
 */
final readonly class PageId implements Identifier
{
    public function __construct(public string $value)
    {
        if (preg_match('/\Apage-[0-9]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('A page id is "page-" and digits.');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }
}
