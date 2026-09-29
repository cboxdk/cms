<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

use Cbox\Cms\Core\Doctor\Domain\Checks\LaravelVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PhpVersionCheck;
use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresVersionCheck;

/**
 * Writes docs/requirements.md from the Requirements of the repository, which `composer
 * docs:requirements` reads from composer.json, package.json and compose.yaml. The section on
 * Composer states only what the resolver enforces: every entry of `require`, with PHP, Laravel
 * (the `illuminate/*` packages) and the PHP extensions first, then what `suggest` lists. The
 * section on development states the Node version of package.json and the images of compose.yaml,
 * and the section on cms:doctor the minimums its checks hold. The same Requirements always give
 * the same bytes.
 */
final readonly class RequirementsPage
{
    /**
     * The path of the page below the repository root.
     */
    public const string PATH = 'docs/requirements.md';

    /**
     * The command that writes the page.
     */
    public const string COMMAND = 'composer docs:requirements';

    /**
     * The compose.yaml service that runs Postgres; the page states the Postgres minimum below its
     * image.
     */
    public const string POSTGRES_SERVICE = 'postgres';

    public static function render(Requirements $requirements): string
    {
        $lines = [
            '---',
            'title: Requirements',
            'weight: 3',
            'description: The PHP, Laravel, extension and package versions Composer enforces, the tools and services for development, and what cms:doctor checks at run time.',
            '---',
            '',
            '# Requirements',
            '',
            '`'.self::COMMAND.'` writes this page from `composer.json`, `package.json` and `compose.yaml`, and a test fails when the page differs from what it writes. Change those files and run it again; do not edit the page by hand.',
            '',
            '## Enforced by Composer',
            '',
            'Cbox CMS is one package, `cboxdk/cms`, with the kernel and the first-party modules as namespaces. Composer installs it only where every requirement of its `composer.json` holds.',
            '',
            '### Platform',
            '',
            ...self::platform($requirements->require),
            '',
            '### Packages',
            '',
            ...self::packages($requirements->require),
            '',
            '### Suggested for development',
            '',
            ...self::suggestions($requirements->suggest),
            '',
            '## For development',
            '',
            ...self::development($requirements),
            '',
            '## Checked by cms:doctor',
            '',
            'Composer cannot check the services and the PHP settings. [cms:doctor](developers/doctor.md) does, when the application runs:',
            '',
            sprintf('- PHP %s or newer, with `allow_url_fopen` off.', PhpVersionCheck::MINIMUM),
            sprintf('- Laravel %d.', LaravelVersionCheck::MAJOR),
            sprintf('- Postgres %d or newer, reachable as the app role, with the role settings of the [operating contract](security/postgres-roles.md), such as `transaction_timeout` above zero, `max_prepared_transactions` 0 and English messages.', PostgresVersionCheck::MINIMUM_MAJOR),
            '- Valkey, answering PING on the configured Redis connection.',
            '- The PHP extensions the connections use: `pdo_pgsql` for Postgres and, with Laravel\'s default Redis client, `redis`. `postgres.reachable` and `valkey.reachable` fail without them.',
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * PHP, Laravel, the extensions and the other platform requirements of `require`.
     *
     * @param  array<string, string>  $require
     * @return list<string>
     */
    private static function platform(array $require): array
    {
        $rows = [];
        $php = $require['php'] ?? null;
        $rows[] = self::row('PHP', $php === null ? 'any version: `composer.json` does not constrain it' : self::code($php));

        $laravel = array_values(array_unique(array_filter(
            $require,
            static fn (string $constraint, string $name): bool => str_starts_with($name, 'illuminate/'),
            ARRAY_FILTER_USE_BOTH,
        )));

        if ($laravel !== []) {
            $rows[] = self::row('Laravel, through the `illuminate/*` packages', implode(', ', array_map(self::code(...), $laravel)));
        }

        $extensions = [];

        foreach (self::sorted($require) as $name => $constraint) {
            if (str_starts_with($name, 'ext-')) {
                $extensions[] = self::row('PHP extension `'.substr($name, strlen('ext-')).'`', self::code($constraint));
            } elseif ($name !== 'php' && ! str_contains($name, '/')) {
                $rows[] = self::row(self::code($name), self::code($constraint));
            }
        }

        $lines = ['| Requirement | Constraint |', '|---|---|', ...$rows, ...$extensions];

        if ($extensions === []) {
            $lines[] = '';
            $lines[] = '`composer.json` requires no PHP extension. The extensions the connections use are checked at run time, as the section on cms:doctor below says.';
        }

        return $lines;
    }

    /**
     * Every package of `require`, by name.
     *
     * @param  array<string, string>  $require
     * @return list<string>
     */
    private static function packages(array $require): array
    {
        $rows = [];

        foreach (self::sorted($require) as $name => $constraint) {
            if (str_contains($name, '/')) {
                $rows[] = self::row(self::code($name), self::code($constraint));
            }
        }

        if ($rows === []) {
            return ['`composer.json` requires no package.'];
        }

        return ['It requires these packages directly:', '', '| Package | Constraint |', '|---|---|', ...$rows];
    }

    /**
     * Every package of `suggest`, by name, with its reason.
     *
     * @param  array<string, string>  $suggest
     * @return list<string>
     */
    private static function suggestions(array $suggest): array
    {
        if ($suggest === []) {
            return ['`composer.json` suggests no package.'];
        }

        $rows = [];

        foreach (self::sorted($suggest) as $name => $reason) {
            $rows[] = self::row(self::code($name), self::escape($reason));
        }

        return [
            'It suggests these and does not require them, so they never reach production. The testkit and the generators need them in development, and an application or addon installs them with `composer require --dev`:',
            '',
            '| Package | Needed for |',
            '|---|---|',
            ...$rows,
        ];
    }

    /**
     * @return list<string>
     */
    private static function development(Requirements $requirements): array
    {
        $lines = [
            $requirements->node === null
                ? '- Node for the JS gates and Playwright; `package.json` states no version in `engines`.'
                : '- Node `'.$requirements->node.'`, as `engines` in `package.json` states it, for the JS gates and Playwright. `cms:doctor --dev` checks Node, Playwright and Chromium.',
            '- Chromium for Playwright, downloaded once per machine with `npx playwright install chromium`.',
        ];

        if ($requirements->services === []) {
            return $lines;
        }

        $lines[] = '';
        $lines[] = 'Docker runs the services of `compose.yaml` from these images:';
        $lines[] = '';
        $lines[] = '| Service | Image |';
        $lines[] = '|---|---|';

        foreach (self::sorted($requirements->services) as $service => $image) {
            $lines[] = self::row(self::code($service), self::code($image));
        }

        if (array_key_exists(self::POSTGRES_SERVICE, $requirements->services)) {
            $lines[] = '';
            $lines[] = sprintf('The tests run on the Postgres of the `%s` service, and the code supports Postgres %d as the minimum: it uses nothing that arrived later.', self::POSTGRES_SERVICE, PostgresVersionCheck::MINIMUM_MAJOR);
        }

        return $lines;
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function sorted(array $values): array
    {
        ksort($values, SORT_STRING);

        return $values;
    }

    private static function row(string $first, string $second): string
    {
        return '| '.$first.' | '.$second.' |';
    }

    /**
     * A value as inline code in a table cell, with its pipes escaped.
     */
    private static function code(string $value): string
    {
        return '`'.self::escape($value).'`';
    }

    private static function escape(string $value): string
    {
        return str_replace('|', '\|', $value);
    }
}
