<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Tests\Support\Phpstan;
use RuntimeException;

/*
 * docs/developers/configuration.md restates what the configuration files say, and this test holds
 * it to them: it names every key. docs/requirements.md is written from composer.json, package.json
 * and compose.yaml by `composer docs:requirements`; RequirementsPageTest holds it to them.
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
