<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Tests\Support\Phpstan;
use RuntimeException;

/*
 * Two pages restate what files of the repository say, and these tests hold them to those files:
 * docs/requirements.md lists what the kernel packages' composer.json files require (the cboxdk docs
 * standard generates it from composer.json and states only what the resolver enforces), and
 * docs/developers/configuration.md names every key of the configuration files.
 */

/**
 * The kernel packages an application installs, not the testkit.
 */
const REFERENCE_RUNTIME_PACKAGES = ['cli', 'contracts', 'core', 'generators', 'http'];

function referenceRead(string $path): string
{
    $contents = file_get_contents(Phpstan::root().'/'.$path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read {$path}.");
    }

    return $contents;
}

/**
 * The require section of a kernel package's composer.json, without the kernel's own packages.
 *
 * @return array<string, string>
 */
function referenceRequires(string $package): array
{
    $composer = json_decode(referenceRead("packages/{$package}/composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $requires = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];
    $result = [];

    foreach ($requires as $name => $constraint) {
        if (is_string($name) && is_string($constraint) && ! str_starts_with($name, 'cboxdk/')) {
            $result[$name] = $constraint;
        }
    }

    ksort($result);

    return $result;
}

function referencePackageName(string $package): string
{
    $composer = json_decode(referenceRead("packages/{$package}/composer.json"), true, flags: JSON_THROW_ON_ERROR);

    return is_array($composer) && is_string($composer['name'] ?? null) ? $composer['name'] : throw new RuntimeException("packages/{$package}/composer.json has no name.");
}

/**
 * A constraint as a table cell writes it: a pipe escaped, inside inline code.
 */
function referenceCell(string $value): string
{
    return '`'.str_replace('|', '\|', $value).'`';
}

/**
 * The table rows of the page between the heading and the next heading.
 *
 * @return list<string>
 */
function referenceTableRows(string $page, string $heading): array
{
    $start = strpos($page, "\n{$heading}\n");

    if ($start === false) {
        throw new RuntimeException("The page has no heading {$heading}.");
    }

    $section = substr($page, $start + strlen($heading) + 2);
    $end = strpos($section, "\n## ");
    $section = $end === false ? $section : substr($section, 0, $end);

    return array_values(array_filter(explode("\n", $section), static fn (string $line): bool => str_starts_with($line, '| `')));
}

/**
 * The dotted keys of a configuration array down to its leaves. A list is a leaf, and so are the
 * maps whose keys are names an application chooses: the contracts, the partitioned tables and the
 * schema roots.
 *
 * @param  array<array-key, mixed>  $config
 * @return list<string>
 */
function referenceConfigKeys(array $config, string $prefix): array
{
    $keys = [];

    foreach ($config as $key => $value) {
        $name = $prefix.'.'.$key;

        if (is_array($value) && ! array_is_list($value) && ! in_array($name, ['cbox-cms.contracts', 'cbox-cms.database.partitions.tables', 'cbox-cms.generators.roots'], true)) {
            array_push($keys, ...referenceConfigKeys($value, $name));
        } else {
            $keys[] = $name;
        }
    }

    return $keys;
}

it('lists on docs/requirements.md exactly what the kernel packages require, with the packages that require it', function (): void {
    /** @var array<string, array{constraint: string, packages: list<string>}> $expected */
    $expected = [];

    foreach (REFERENCE_RUNTIME_PACKAGES as $package) {
        foreach (referenceRequires($package) as $name => $constraint) {
            expect($expected[$name]['constraint'] ?? $constraint)->toBe($constraint, "{$name} has two constraints in the kernel packages");
            $expected[$name]['constraint'] = $constraint;
            $expected[$name]['packages'][] = referencePackageName($package);
        }
    }

    ksort($expected);
    $rows = [];

    foreach ($expected as $name => $row) {
        $rows[] = '| `'.$name.'` | '.referenceCell($row['constraint']).' | '.implode(', ', array_map(static fn (string $package): string => '`'.$package.'`', $row['packages'])).' |';
    }

    $testkit = array_map(
        static fn (string $name, string $constraint): string => '| `'.$name.'` | '.referenceCell($constraint).' |',
        array_keys(referenceRequires('testkit')),
        referenceRequires('testkit'),
    );

    expect(referenceTableRows(referenceRead('docs/requirements.md'), '## Enforced by Composer'))->toBe([...$rows, ...$testkit]);
});

it('names every key of the configuration files on docs/developers/configuration.md', function (): void {
    $core = require Phpstan::root().'/packages/core/config/cbox-cms.php';
    $generators = require Phpstan::root().'/packages/generators/config/generators.php';

    expect($core)->toBeArray()->and($generators)->toBeArray();

    $keys = [
        ...referenceConfigKeys(is_array($core) ? $core : [], 'cbox-cms'),
        ...referenceConfigKeys(is_array($generators) ? $generators : [], 'cbox-cms.generators'),
    ];
    $page = referenceRead('docs/developers/configuration.md');

    expect($keys)->toContain('cbox-cms.contracts', 'cbox-cms.database.partitions.tables', 'cbox-cms.doctor.checks', 'cbox-cms.generators.php_namespace')
        ->and(array_values(array_filter($keys, static fn (string $key): bool => ! str_contains($page, '| `'.$key.'` |'))))->toBe([]);
});
