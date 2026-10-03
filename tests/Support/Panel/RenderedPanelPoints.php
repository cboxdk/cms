<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Panel;

use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelPointEntry;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Holds the panel's pages to the panel registry (PRD 13.4): a page renders a point with the host
 * element `<PointHost point="<name>@<version>" ...>`, and every point it renders must be one a
 * #[PanelPoint] declares, every declared point must be rendered by a page, and the point is a
 * string literal, so this check can read it.
 *
 * It reads the .ts and .tsx files below a directory, with comments blanked out and line numbers
 * kept, and reports each finding as `<file>:<line>: <message>` with the file relative to the
 * repository, or `<id> (<class>): <message>` for a point no page renders.
 */
final readonly class RenderedPanelPoints
{
    /** The element a page renders a panel point with. */
    public const string HOST = 'PointHost';

    /**
     * @param  list<PanelPointEntry>  $declared
     * @return list<string>
     */
    public static function findings(string $pages, string $root, array $declared): array
    {
        $byId = [];

        foreach ($declared as $point) {
            $byId[$point->id()->toString()] = $point;
        }

        $findings = [];
        $rendered = [];

        foreach (self::files($pages) as $file) {
            $relative = ltrim(substr($file, strlen(rtrim($root, '/'))), '/');
            $source = self::withoutComments(self::read($file));

            if (preg_match_all('/<'.self::HOST.'\b(?<attributes>[^>]*)/', $source, $hosts, PREG_OFFSET_CAPTURE) === false) {
                throw new RuntimeException(sprintf('Could not search %s.', $file));
            }

            foreach ($hosts['attributes'] as [$attributes, $offset]) {
                $line = substr_count($source, "\n", 0, $offset) + 1;

                if (preg_match('/\bpoint=(?:"(?<double>[^"]*)"|\'(?<single>[^\']*)\')/', $attributes, $match) !== 1) {
                    $findings[] = sprintf('%s:%d: <%s> without a literal point id, such as point="account.me.sections@1"', $relative, $line, self::HOST);

                    continue;
                }

                $text = $match['double'] !== '' ? $match['double'] : ($match['single'] ?? '');

                try {
                    $id = PointId::fromString($text)->toString();
                } catch (InvalidPanelPoint) {
                    $findings[] = sprintf('%s:%d: renders "%s", which is not a panel point id', $relative, $line, $text);

                    continue;
                }

                if (! array_key_exists($id, $byId)) {
                    $findings[] = sprintf('%s:%d: renders %s, which no #[PanelPoint] declares', $relative, $line, $id);

                    continue;
                }

                $rendered[$id] = true;
            }
        }

        foreach ($byId as $id => $point) {
            if (! array_key_exists($id, $rendered)) {
                $findings[] = sprintf('%s (%s): declared, but no page renders it', $id, $point->class);
            }
        }

        return $findings;
    }

    /**
     * The .ts and .tsx files below a directory, sorted by path.
     *
     * @return list<string>
     */
    private static function files(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private static function read(string $file): string
    {
        $source = file_get_contents($file);

        return is_string($source) ? $source : throw new RuntimeException(sprintf('Could not read %s.', $file));
    }

    /**
     * The source with every block and line comment replaced by spaces, newlines kept, so a host
     * element in a comment is not read and every line keeps its number.
     */
    private static function withoutComments(string $source): string
    {
        $blanked = preg_replace_callback(
            '~/\*.*?\*/|//[^\n]*~s',
            static fn (array $comment): string => preg_replace('/[^\n]/', ' ', $comment[0]) ?? '',
            $source,
        );

        return $blanked ?? throw new RuntimeException('Could not blank out the comments.');
    }
}
