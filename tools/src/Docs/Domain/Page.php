<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * A documentation page as PageParser reads it.
 */
final readonly class Page
{
    /**
     * @param  string  $path  repo-relative
     * @param  list<Marker>  $markers  every marker outside fenced blocks, in order
     * @param  list<Embed>  $embeds  the example and example-file markers that a fenced block follows
     * @param  list<int>  $strayFences  the opening lines of fenced blocks no embedding marker precedes
     * @param  list<int>  $unclosedFences  the opening lines of fenced blocks that run to the end of the page
     * @param  ?Frontmatter  $frontmatter  the block of `key: value` lines at the top, or null when the page has none
     * @param  list<Link>  $links  every link outside fenced blocks and inline code, in order
     * @param  list<string>  $anchors  the anchor of every heading outside fenced blocks, as GitHub makes them
     */
    public function __construct(
        public string $path,
        public array $markers,
        public array $embeds,
        public array $strayFences,
        public array $unclosedFences,
        public ?Frontmatter $frontmatter,
        public array $links,
        public array $anchors,
    ) {}

    /**
     * @return list<Marker>
     */
    public function markers(MarkerKind $kind): array
    {
        return array_values(array_filter($this->markers, static fn (Marker $marker): bool => $marker->kind === $kind));
    }

    /**
     * @return list<Embed>
     */
    public function embeds(MarkerKind $kind): array
    {
        return array_values(array_filter($this->embeds, static fn (Embed $embed): bool => $embed->marker->kind === $kind));
    }

    /**
     * The embedding markers that no fenced block follows on the next line.
     *
     * @return list<Marker>
     */
    public function markersWithoutBlock(): array
    {
        $embedded = array_map(static fn (Embed $embed): Marker => $embed->marker, $this->embeds);

        return array_values(array_filter(
            $this->markers,
            static fn (Marker $marker): bool => $marker->kind->embeds() && ! in_array($marker, $embedded, true),
        ));
    }
}
