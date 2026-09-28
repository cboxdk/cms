<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * One cell of the terminal of a screenshot: a character, one UTF-8 code point, and its style.
 */
final readonly class TerminalCell
{
    public function __construct(
        public string $character,
        public TerminalStyle $style,
    ) {}
}
