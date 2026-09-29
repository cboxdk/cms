<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * The read port of the query action: titles by note. The example holds them in memory.
 */
final readonly class NoteTitles
{
    /**
     * @param  array<string, string>  $titles  the titles by note id
     */
    public function __construct(
        private array $titles,
    ) {}

    public function of(EntryId $note): ?string
    {
        return $this->titles[$note->toString()] ?? null;
    }
}
