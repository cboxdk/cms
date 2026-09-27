<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * An example or example-file marker with the fenced block that follows it on the next line, and
 * the block's content: every line between the fences, each with its newline.
 */
final readonly class Embed
{
    public function __construct(
        public Marker $marker,
        public string $body,
    ) {}
}
