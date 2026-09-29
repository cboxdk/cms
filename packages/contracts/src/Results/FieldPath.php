<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where in a command's input an error is (PRD 6.1, GUARDRAILS 2.1): a list of segments, each a
 * name or a list index, such as fields, ext, app, tax_code or blocks, 2, text. A name is a letter
 * or an underscore followed by letters, digits and underscores, so both a command's properties and
 * field handles fit; an index is 0 or more. The first segment is a name. toString() writes names
 * joined by dots and indexes in brackets: "blocks[2].text", and fromString() reads that form back.
 */
#[Experimental]
final readonly class FieldPath
{
    private const string NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * The form toString() writes: a name, then a dot and a name or an index in brackets, any
     * number of times. An index has at most 18 digits, so it fits an int.
     */
    private const string WRITTEN = '/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*|\[(?:0|[1-9][0-9]{0,17})\])*\z/';

    /** One segment of the written form. */
    private const string SEGMENT = '/[A-Za-z_][A-Za-z0-9_]*|\[([0-9]+)\]/';

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
     * The path toString() writes, such as "blocks[2].text".
     *
     * @throws InvalidWriteResult when $value is not in that form
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::WRITTEN, $value) !== 1) {
            throw InvalidWriteResult::path($value);
        }

        preg_match_all(self::SEGMENT, $value, $matches, PREG_SET_ORDER);
        $segments = [];

        foreach ($matches as $match) {
            $segments[] = isset($match[1]) ? (int) $match[1] : $match[0];
        }

        return new self((string) array_shift($segments), ...$segments);
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
