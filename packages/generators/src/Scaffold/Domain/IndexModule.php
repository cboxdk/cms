<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\RegistrationEntry;

/**
 * The registration module of an addon's panel UI (section 3.1 of the panel extension
 * architecture), resources/panel/src/index.ts, whose default export is definePanelAddon() over the
 * contributions, and ids.ts beside it, the ids of the contributions that run code, which the
 * registration's test and the build plugin read. cms:make:addon-ui writes both; cms:make:panel
 * adds a contribution to each, keeping what the addon wrote around it.
 */
#[Internal]
final readonly class IndexModule
{
    public const string INDEX = ScaffoldNames::SOURCE.'/index.ts';

    public const string IDS = ScaffoldNames::SOURCE.'/ids.ts';

    public const string INDEX_TEST = ScaffoldNames::SOURCE.'/index.test.ts';

    /** The line the entries of the registration follow. */
    private const string OPENING = 'export default definePanelAddon<Contributions>({';

    private function __construct() {}

    /**
     * The registration module with the entries, sorted by id.
     *
     * @param  list<RegistrationEntry>  $entries
     */
    public static function index(AddonNamespace $namespace, array $entries): GeneratedFile
    {
        usort($entries, static fn (RegistrationEntry $a, RegistrationEntry $b): int => strcmp($a->id->value, $b->id->value));
        $imports = [];

        foreach ($entries as $entry) {
            if ($entry->import !== null) {
                $imports[] = $entry->import;
            }
        }

        sort($imports, SORT_STRING);

        $lines = [
            sprintf('// The panel UI of the addon %s: the registration of its contributions, the default export', $namespace->value),
            "// of its bundle's entry (PRD 13.4). Each key is a contribution of the manifest that runs code,",
            '// and cms:panel:types writes Contributions from the manifest, so tsc refuses a missing key, an',
            '// extra key and a component or function with other props.',
            '',
            "import { definePanelAddon } from '@cboxdk/cms-panel/extend';",
            '',
            "import type { Contributions } from '../generated/contributions';",
            ...$imports,
            '',
            ...($entries === [] ? [self::OPENING.'});'] : [
                self::OPENING,
                ...array_map(self::line(...), $entries),
                '});',
            ]),
            '',
        ];

        return new GeneratedFile(self::INDEX, implode("\n", $lines));
    }

    /**
     * The ids module with the ids, sorted.
     *
     * @param  list<string>  $ids
     */
    public static function ids(AddonNamespace $namespace, array $ids): GeneratedFile
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        $lines = [
            sprintf('// The ids of the contributions of %s that run code, as its manifest declares them: what', $namespace->value),
            '// the registration holds and what the build plugin names in panel-manifest.json. Written by',
            '// cms:make:addon-ui from the registry; cms:make:panel adds to it.',
            '',
            ...($ids === []
                ? ['export const CONTRIBUTIONS: readonly string[] = [];']
                : ['export const CONTRIBUTIONS: readonly string[] = [', ...array_map(static fn (string $id): string => '  '.ScaffoldNames::quote($id).',', $ids), '];']),
            '',
        ];

        return new GeneratedFile(self::IDS, implode("\n", $lines));
    }

    /**
     * The test that holds the registration to the ids.
     */
    public static function indexTest(AddonNamespace $namespace): GeneratedFile
    {
        $lines = [
            sprintf('// The registration of %s is the contributions of its manifest that run code, as cms:build', $namespace->value),
            '// compiled them, so the panel renders them: with a missing or an extra key it would render none.',
            '',
            "import { expectRegistration } from '@cboxdk/cms-panel/testing';",
            "import { test } from 'vitest';",
            '',
            "import { CONTRIBUTIONS } from './ids';",
            "import addon from './index';",
            '',
            "test('the registration is the manifest s contributions that run code', () => {",
            '  expectRegistration(addon, CONTRIBUTIONS);',
            '});',
            '',
        ];

        return new GeneratedFile(self::INDEX_TEST, implode("\n", $lines));
    }

    /**
     * The registration module with the entry added, or null when the module is not laid out as
     * cms:make:addon-ui writes it, so the entry has to be added by hand.
     */
    public static function withEntry(string $source, RegistrationEntry $entry): ?string
    {
        $opening = strpos($source, self::OPENING);

        if ($opening === false || str_contains($source, ScaffoldNames::quote($entry->id->value).':')) {
            return null;
        }

        $start = $opening + strlen(self::OPENING);
        $closed = str_starts_with(substr($source, $start), '});');
        $newline = ! $closed && substr($source, $start, 1) === "\n";
        $head = substr($source, 0, $start)."\n";
        $tail = substr($source, $newline ? $start + 1 : $start);
        $source = $head.self::line($entry)."\n".$tail;

        if ($entry->import !== null && ! str_contains($source, $entry->import)) {
            $lastImport = strrpos($source, "\nimport ");

            if ($lastImport === false) {
                return null;
            }

            $end = strpos($source, "\n", $lastImport + 1);

            if ($end === false) {
                return null;
            }

            $source = substr($source, 0, $end + 1).$entry->import."\n".substr($source, $end + 1);
        }

        return $source;
    }

    /**
     * The ids of an ids module as cms:make:addon-ui writes it, or null when it is laid out
     * otherwise.
     *
     * @return list<string>|null
     */
    public static function idsOf(string $source): ?array
    {
        if (preg_match('/export const CONTRIBUTIONS: readonly string\[\] = \[(.*?)\];/s', $source, $match) !== 1) {
            return null;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $ids);

        return array_map(static fn (string $id): string => str_replace(["\\'", '\\\\'], ["'", '\\'], $id), $ids[1]);
    }

    private static function line(RegistrationEntry $entry): string
    {
        return '  '.ScaffoldNames::quote($entry->id->value).': '.$entry->expression.',';
    }
}
