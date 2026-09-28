<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * Which screenshots the documentation shows, and what each one is for.
 *
 * One list, so a command whose output changes has one place to update, and a shot no page embeds
 * is a finding of `composer docs:check` instead of a stale image nobody notices. Each shot is the
 * real output of its command, run by `composer docs:screenshots` from the repository root and drawn
 * as a terminal window in docs/screenshots/<key>.svg, which is committed. Run it in the php
 * container of `composer services:up`, `docker compose exec php composer docs:screenshots`, after
 * `composer dev:prepare`, so the output is that of the development environment the pages describe.
 *
 * Adding one:
 *
 *  1. an entry here, with the caption the pages use and the exit code the command must end with;
 *  2. `![<caption>](<relative path>/screenshots/<key>.svg)` on the page that describes it;
 *  3. `composer docs:screenshots -- --only=<key>`.
 *
 * `composer docs:check` then holds the three together: every entry has its image, every image
 * below docs/screenshots has an entry, every entry is embedded by a page outside
 * docs/screenshots, and every embed uses the entry's caption.
 */
final readonly class Screenshots
{
    /**
     * @return list<Screenshot>
     */
    public static function all(): array
    {
        return [
            new Screenshot(
                'doctor',
                ['vendor/bin/testbench', 'cms:doctor'],
                'cms:doctor in the development container. Every runtime check passes, and each line says what the check looked at and what it found.',
            ),
            new Screenshot(
                'doctor-violation',
                ['php', '-d', 'allow_url_fopen=1', 'vendor/bin/testbench', 'cms:doctor'],
                'cms:doctor with allow_url_fopen turned on. The failing check gives the cause, the fix and the error code, and the doctor exits 78.',
                exitCode: 78,
            ),
        ];
    }

    /**
     * The shot with the key, or null.
     */
    public static function find(string $key): ?Screenshot
    {
        return array_find(self::all(), static fn (Screenshot $shot): bool => $shot->key === $key);
    }
}
