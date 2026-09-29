<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\FixtureSupport;

use Cbox\Cms\Contracts\Ids\Identifier;

/**
 * The id of the registry fixtures' aggregate, a note.
 */
final readonly class NoteId implements Identifier
{
    public function __construct(public string $value) {}

    public function toString(): string
    {
        return $this->value;
    }
}
