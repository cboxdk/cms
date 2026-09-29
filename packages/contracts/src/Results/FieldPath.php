<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where in a command's input an error is (PRD 6.1, GUARDRAILS 2.1): a list of segments, each a
 * name or a list index, such as fields, ext, app, tax_code or blocks, 2, text. A name is a letter
 * or an underscore followed by letters, digits and underscores, so both a command's properties and
 * field handles fit; an index is 0 or more. The first segment is a name. toString() writes names
 * joined by dots and indexes in brackets: "blocks[2].text".
 */
#[Experimental]
final readonly class FieldPath
{
    private const string NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /** @var list<string|int> */
    public array $segments;

    private string $first;

    public function __construct(string $first, string|int ...$rest)
    {
        $segments = [$first, ...array_values($rest)];

        foreach ($segments as $segment) {
            if (is_int($segment) ? $segment < 0 : preg_match(self::NAME, $segment) !== 1) {
                throw InvalidWriteResult::pathSegment($segment);
            }
        }

        $this->first = $first;
        $this->segments = $segments;
    }

    /**
     * A new path with the segments after this path's segments.
     */
    public function then(string|int ...$segments): self
    {
        return new self($this->first, ...array_slice($this->segments, 1), ...$segments);
    }

    public function toString(): string
    {
        $path = '';

        foreach ($this->segments as $segment) {
            $path .= is_int($segment) ? '['.$segment.']' : ($path === '' ? '' : '.').$segment;
        }

        return $path;
    }

    public function equals(self $other): bool
    {
        return $this->segments === $other->segments;
    }
}
