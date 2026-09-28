<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * A link on a page, outside fenced blocks and inline code: `[text](target)`, an image
 * `![text](target)`, or a reference definition `[label]: target`.
 */
final readonly class Link
{
    public function __construct(
        public string $target,
        public string $text,
        public int $line,
        public bool $image,
    ) {}

    /**
     * Whether the target points into the repository: not a URL with a scheme, such as https: or
     * mailto:, and not protocol-relative.
     */
    public function isRelative(): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $this->target) !== 1 && ! str_starts_with($this->target, '//');
    }

    /**
     * The path part of the target, percent-decoded, without the query and the fragment; empty for a
     * link to a heading on the same page.
     */
    public function path(): string
    {
        $path = (string) preg_replace('/[?#].*$/s', '', $this->target);

        return rawurldecode($path);
    }

    /**
     * The fragment of the target without `#`, or null when it has none.
     */
    public function fragment(): ?string
    {
        $position = strpos($this->target, '#');

        return $position === false ? null : rawurldecode(substr($this->target, $position + 1));
    }
}
