<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * A marker on a page, what it names and its line.
 */
final readonly class Marker
{
    public function __construct(
        public MarkerKind $kind,
        public string $target,
        public int $line,
    ) {}

    /**
     * Whether the target is a repo-relative path: no leading slash, no backslash, no scheme and no
     * empty, `.` or `..` segment. It is the one convention for the paths in markers.
     */
    public function isRepoRelativePath(): bool
    {
        if ($this->target === '' || str_contains($this->target, '\\') || str_contains($this->target, '://')) {
            return false;
        }

        return array_all(explode('/', $this->target), static fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true));
    }

    public function text(): string
    {
        return "<!-- {$this->kind->value}: {$this->target} -->";
    }
}
