<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Tests\Support\Phpstan;
use RuntimeException;

/*
 * Two pages restate what files of the repository say, and these tests hold them to those files:
 * docs/requirements.md lists what cboxdk/cms's composer.json requires and suggests (the cboxdk docs
 * standard generates it from composer.json and states only what the resolver enforces), and
 * docs/developers/configuration.md names every key of the configuration files.
 */

function referenceRead(string $path): string
{
    $contents = file_get_contents(Phpstan::root().'/'.$path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read {$path}.");
    }

    return $contents;
}

/**
 * A section of the root composer.json, cboxdk/cms, as a map of strings.
 *
 * @return array<string, string>
 */
function referenceComposer(string $section): array
{
    $composer = json_decode(referenceRead('composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $values = is_array($composer) && is_array($composer[$section] ?? null) ? $composer[$section] : [];
    $result = [];

    foreach ($values as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $result[$name] = $value;
        }
    }

    ksort($result);

    return $result;
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

it('lists on docs/requirements.md exactly what cboxdk/cms requires, and what it suggests with the reason', function (): void {
    $required = array_map(
        static fn (string $name, string $constraint): string => '| `'.$name.'` | '.referenceCell($constraint).' |',
        array_keys(referenceComposer('require')),
        referenceComposer('require'),
    );
    $suggested = array_map(
        static fn (string $name, string $reason): string => '| `'.$name.'` | '.$reason.' |',
        array_keys(referenceComposer('suggest')),
        referenceComposer('suggest'),
    );

    expect($required)->not->toBe([])
        ->and($suggested)->not->toBe([])
        ->and(referenceTableRows(referenceRead('docs/requirements.md'), '## Enforced by Composer'))->toBe([...$required, ...$suggested]);
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
