<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The frontmatter of a page: the block between a first line `---` and the next line `---`, read
 * as `key: value` lines. The docs site orders and describes the pages by it, so every page has
 * `title`, `weight` and `description` (the cboxdk docs standard).
 */
final readonly class Frontmatter
{
    /**
     * The keys every page has.
     *
     * @var list<string>
     */
    public const array REQUIRED = ['title', 'weight', 'description'];

    /**
     * @param  array<string, string>  $values  the value of each key, unquoted
     * @param  list<int>  $invalidLines  the lines inside the block that are not `key: value`
     * @param  int  $lastLine  the line of the closing `---`
     */
    public function __construct(
        public array $values,
        public array $invalidLines,
        public int $lastLine,
    ) {}

    /**
     * The value of the key with surrounding white space removed, or null when the key is missing.
     */
    public function value(string $key): ?string
    {
        return array_key_exists($key, $this->values) ? trim($this->values[$key]) : null;
    }

    /**
     * The weight as an integer, or null when it is missing or not a whole number.
     */
    public function weight(): ?int
    {
        $weight = $this->value('weight');

        return $weight !== null && preg_match('/^-?\d{1,9}$/', $weight) === 1 ? (int) $weight : null;
    }
}
