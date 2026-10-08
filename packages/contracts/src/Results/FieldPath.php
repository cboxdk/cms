<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where in a command's input an error is (PRD 6.1, GUARDRAILS 2.1): a list of segments, each a
 * name, a list index or the key of one item of a list, such as fields, ext, app, tax_code or
 * blocks, 2, text. A name is a letter or an underscore followed by letters, digits and underscores,
 * so both a command's properties and field handles fit; an index is 0 or more; an item key is an
 * ItemKey, which names the item of a list that carries that key instead of its place, so the path
 * survives a reorder (PRD 11.10, decision D3 of the editing experience proposal). The first
 * segment is a name. toString() writes names joined by dots, indexes in brackets and item keys in
 * brackets after a hash: "blocks[2].text" and "fields.body[#k3f9].heading", and fromString() reads
 * that form back. The panel's TypeScript twin, js/panel/src/forms/field-path.ts, reads and writes
 * the same grammar, and both are held to the shared examples in resources/field-paths.json.
 */
#[Experimental]
final readonly class FieldPath
{
    private const string NAME = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * The form toString() writes: a name, then any number of a dot and a name, an index in
     * brackets, or an item key in brackets after a hash. An index has at most 18 digits, so it
     * fits an int.
     */
    private const string WRITTEN = '/\A(?<first>[A-Za-z_][A-Za-z0-9_]*)(?<rest>(?:\.[A-Za-z_][A-Za-z0-9_]*|\[(?:0|[1-9][0-9]{0,17})\]|\[#[A-Za-z0-9_-]{1,64}\])*)\z/';

    /** One segment after the first, as the written form holds it: ".name", "[0]" or "[#key]". */
    private const string SEGMENT = '/\.[A-Za-z_][A-Za-z0-9_]*|\[#[A-Za-z0-9_-]+\]|\[[0-9]+\]/';

    /** @var non-empty-list<string|int|ItemKey> */
    public array $segments;

    private string $first;

    public function __construct(string $first, string|int|ItemKey ...$rest)
    {
        if (preg_match(self::NAME, $first) !== 1) {
            throw InvalidWriteResult::pathSegment($first);
        }

        foreach ($rest as $segment) {
            if (is_int($segment) && $segment < 0) {
                throw InvalidWriteResult::pathSegment($segment);
            }

            if (is_string($segment) && preg_match(self::NAME, $segment) !== 1) {
                throw InvalidWriteResult::pathSegment($segment);
            }
        }

        $this->first = $first;
        $this->segments = [$first, ...array_values($rest)];
    }

    /**
     * The path toString() writes, such as "blocks[2].text" or "fields.body[#k3f9].heading".
     *
     * @throws InvalidWriteResult when $value is not in that form
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::WRITTEN, $value, $written) !== 1) {
            throw InvalidWriteResult::path($value);
        }

        preg_match_all(self::SEGMENT, $written['rest'], $matches);
        $rest = [];

        foreach ($matches[0] as $segment) {
            $rest[] = match (true) {
                $segment[0] === '.' => substr($segment, 1),
                $segment[1] === '#' => new ItemKey(substr($segment, 2, -1)),
                default => (int) substr($segment, 1, -1),
            };
        }

        return new self($written['first'], ...$rest);
    }

    /**
     * A new path with the segments after this path's segments.
     */
    public function then(string|int|ItemKey ...$segments): self
    {
        return new self($this->first, ...array_slice($this->segments, 1), ...$segments);
    }

    public function toString(): string
    {
        $path = '';

        foreach ($this->segments as $segment) {
            $path .= match (true) {
                is_int($segment) => '['.$segment.']',
                $segment instanceof ItemKey => '[#'.$segment->value.']',
                default => ($path === '' ? '' : '.').$segment,
            };
        }

        return $path;
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }
}
