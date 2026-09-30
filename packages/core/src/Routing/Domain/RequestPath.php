<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The path a request resolves (PRD 5.9): "/" or segments, each a "/" followed by characters that
 * are neither a slash nor white space, as a node's route is written, and no segment "." or "..". A
 * trailing slash, an empty segment or a dot segment is not a path: normalising a URL, and the
 * redirect that goes with it, is the surface's work. It has at most MAX_SEGMENTS segments and
 * MAX_BYTES bytes, so the prefixes a resolution looks up are bounded.
 */
#[Experimental]
final readonly class RequestPath
{
    /** The most segments a path has. */
    public const int MAX_SEGMENTS = 32;

    /** The longest path, in bytes. */
    public const int MAX_BYTES = 2048;

    private const string PATTERN = '/\A(?:\/|(?:\/[^\/\s]+)+)\z/u';

    /**
     * @throws InvalidRoutingValue for anything else
     */
    public function __construct(public string $value)
    {
        if (
            strlen($value) > self::MAX_BYTES
            || preg_match(self::PATTERN, $value) !== 1
            || count($this->segments()) > self::MAX_SEGMENTS
            || array_intersect($this->segments(), ['.', '..']) !== []
        ) {
            throw InvalidRoutingValue::path($value);
        }
    }

    /**
     * Every prefix of the path a route can be, the longest first: the path itself, each shorter
     * run of its leading segments, and "/" (PRD 5.9 step 2).
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        $segments = $this->segments();
        $prefixes = [];

        for ($count = count($segments); $count > 0; $count--) {
            $prefixes[] = '/'.implode('/', array_slice($segments, 0, $count));
        }

        $prefixes[] = '/';

        return $prefixes;
    }

    /**
     * What is left of the path after the route, one of its prefixes, without the slash between
     * them: "" when the route is the whole path, and null when it is not a prefix of the path.
     */
    public function rest(string $route): ?string
    {
        return match (true) {
            ! in_array($route, $this->prefixes(), true) => null,
            $route === '/' => substr($this->value, 1),
            default => ltrim(substr($this->value, strlen($route)), '/'),
        };
    }

    /**
     * @return list<string>
     */
    private function segments(): array
    {
        return $this->value === '/' ? [] : explode('/', substr($this->value, 1));
    }
}
