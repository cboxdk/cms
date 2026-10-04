<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * What the registry files cms:build wrote say about the panel (PRD 13.2, 13.4), read by the
 * PanelContributionsContract: the addons of addons.php by namespace, and the contributions of each
 * panel point of panel.php in the order the panel renders them. It reads the arrays the files
 * return as any application does and names nothing of the kernel's code, so the testkit stays on
 * the contracts alone.
 */
#[Experimental]
final readonly class CompiledPanelFiles
{
    /**
     * @param  list<string>  $addons  the namespaces of the compiled addons
     * @param  array<string, list<string>>  $fills  the contribution ids of each point, by the point's id
     */
    private function __construct(
        public array $addons,
        public array $fills,
    ) {}

    /**
     * Reads addons.php and panel.php from the directory cms:build wrote them to.
     *
     * @throws RuntimeException when a file is missing or not what cms:build writes
     */
    public static function read(string $directory): self
    {
        $addons = [];

        foreach (self::entries($directory.'/addons.php') as $entry) {
            $namespace = $entry['namespace'] ?? null;

            if (is_string($namespace)) {
                $addons[] = $namespace;
            }
        }

        $fills = [];

        foreach (self::entries($directory.'/panel.php') as $entry) {
            $point = $entry['id'] ?? null;

            if (! is_string($point)) {
                continue;
            }

            $ids = [];

            foreach (is_array($entry['fills'] ?? null) ? $entry['fills'] : [] as $fill) {
                $id = is_array($fill) ? ($fill['contribution'] ?? null) : null;

                if (is_string($id)) {
                    $ids[] = $id;
                }
            }

            $fills[$point] = $ids;
        }

        return new self($addons, $fills);
    }

    /**
     * The contribution ids of the point, in render order, or none when the registry has no such
     * point or no contribution to it.
     *
     * @return list<string>
     */
    public function contributionsOf(string $point): array
    {
        return $this->fills[$point] ?? [];
    }

    /**
     * The entries of a registry file.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException
     */
    private static function entries(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException(sprintf('cms:build wrote no %s; the build did not run, or its cache is elsewhere.', $file));
        }

        $compiled = require $file;

        if (! is_array($compiled) || ! is_array($compiled['entries'] ?? null)) {
            throw new RuntimeException(sprintf('%s is not a registry file: it returns no array with entries.', $file));
        }

        $entries = [];

        foreach ($compiled['entries'] as $entry) {
            if (is_array($entry)) {
                $entries[] = array_filter($entry, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $entries;
    }
}
