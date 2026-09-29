<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\ReadContent;
use Override;

/**
 * A result that carries the fields of the notes it read. The query pipeline takes the notes from
 * contents(), strips the fields the principal may not read, and puts them back with
 * withContents(), so the caller only ever gets the stripped notes.
 */
final readonly class NoteCards implements ReadsContent
{
    /**
     * @param  list<ReadContent>  $notes
     */
    public function __construct(
        public array $notes,
    ) {}

    /**
     * @return list<ReadContent>
     */
    #[Override]
    public function contents(): array
    {
        return $this->notes;
    }

    /**
     * @param  list<ReadContent>  $contents
     */
    #[Override]
    public function withContents(array $contents): static
    {
        return new self($contents);
    }
}
