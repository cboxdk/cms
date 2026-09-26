<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use InvalidArgumentException;

/**
 * What an OutputReader found in the output of a step that ran: notes to list with the step, such
 * as the abandoned packages of an audit, and a failure its exit code does not show.
 */
final readonly class OutputReading
{
    /**
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $notes = [],
        public ?string $failure = null,
    ) {
        foreach ($notes as $note) {
            if ($note === '' || str_contains($note, "\n")) {
                throw new InvalidArgumentException('A note is one line of text.');
            }
        }

        if ($failure === '') {
            throw new InvalidArgumentException('A failure needs a reason.');
        }
    }
}
