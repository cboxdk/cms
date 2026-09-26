<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CommandEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\HookEntry;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use PHPUnit\Framework\Assert;

function codecRegistry(): CompiledRegistry
{
    return new CompiledRegistry(
        [new ActionEntry('App\Actions\CreateNote', 'acme/notes', [Surface::Cli, Surface::Rest])],
        [new CommandEntry('note.create', 1, 'App\Commands\CreateNote', 'acme/notes')],
        [new HookEntry('App\Hooks\Trim', 'acme/notes', 'note.create', 1, 'App\Commands\CreateNote', Phase::Transform, -5, 3)],
    );
}

/**
 * What each file of the registry returns, keyed by registry name, as the adapter passes it.
 *
 * @return array<string, mixed>
 */
function codecFiles(CompiledRegistry $registry): array
{
    $files = [];

    foreach (new RegistryCacheCodec()->encode($registry) as $name => $source) {
        $path = tempnam(sys_get_temp_dir(), 'cms-codec-');
        Assert::assertIsString($path);
        file_put_contents($path, $source);
        $files[$name] = RegistryFixtures::load($path);
        unlink($path);
    }

    return $files;
}

/**
 * @param  mixed  $damaged  what a dataset's damage returned: the files, keyed by registry name
 */
function codecFailure(mixed $damaged): MalformedRegistryCache
{
    Assert::assertIsArray($damaged);
    $files = [];

    foreach ($damaged as $name => $file) {
        $files[(string) $name] = $file;
    }

    try {
        new RegistryCacheCodec()->decode($files, '/cache');
    } catch (MalformedRegistryCache $malformed) {
        return $malformed;
    }

    Assert::fail('The codec read a malformed cache.');
}

it('writes the exact bytes of format 1', function (): void {
    $files = new RegistryCacheCodec()->encode(codecRegistry());
    $header = "<?php\n\ndeclare(strict_types=1);\n\n// Written by php artisan cms:build from the attributes in the declared scan roots (PRD 13.2).\n// Do not edit and do not commit; run cms:build again instead.\n\n";

    expect(array_keys($files))->toBe(['actions', 'commands', 'hooks', 'subscribers', 'slots', 'schema'])
        ->and($files['actions'])->toBe($header.<<<'PHP'
            return [
                'entries' => [
                    [
                        'class' => 'App\\Actions\\CreateNote',
                        'package' => 'acme/notes',
                        'surfaces' => [
                            'rest',
                            'cli',
                        ],
                    ],
                ],
                'format' => 1,
                'registry' => 'actions',
            ];

            PHP)
        ->and($files['hooks'])->toBe($header.<<<'PHP'
            return [
                'entries' => [
                    [
                        'budget_ms' => 3,
                        'class' => 'App\\Hooks\\Trim',
                        'command' => 'note.create',
                        'command_class' => 'App\\Commands\\CreateNote',
                        'command_version' => 1,
                        'package' => 'acme/notes',
                        'phase' => 'transform',
                        'priority' => -5,
                    ],
                ],
                'format' => 1,
                'registry' => 'hooks',
            ];

            PHP)
        ->and($files['slots'])->toBe($header."return [\n    'entries' => [],\n    'format' => 1,\n    'registry' => 'slots',\n];\n");
});

it('reads back what it writes', function (): void {
    expect(new RegistryCacheCodec()->decode(codecFiles(codecRegistry()), '/cache'))->toEqual(codecRegistry())
        ->and(new RegistryCacheCodec()->decode(codecFiles(CompiledRegistry::empty()), '/cache'))->toEqual(CompiledRegistry::empty());
});

it('writes the same bytes for the same registry', function (): void {
    expect(new RegistryCacheCodec()->encode(codecRegistry()))->toBe(new RegistryCacheCodec()->encode(codecRegistry()));
});

it('escapes the backslashes in class names so the file stays valid PHP', function (): void {
    $source = new RegistryCacheCodec()->encode(codecRegistry())['commands'];

    expect($source)->toContain("'class' => 'App\\\\Commands\\\\CreateNote'");
});

it('refuses a malformed cache with the file and the place in it', function (callable $damage, string $file, string $expected): void {
    $files = codecFiles(codecRegistry());
    $files = $damage($files);

    $malformed = codecFailure($files);

    expect($malformed->getMessage())
        ->toStartWith('[registry_cache_malformed] The registry cache file /cache/'.$file)
        ->toContain($expected)
        ->toContain('run php artisan cms:build');
})->with([
    'a missing file' => [static function (array $files): array {
        unset($files['hooks']);

        return $files;
    }, 'hooks.php', 'expected an array with the keys entries, format, registry, got null'],
    'another format' => [static function (array $files): array {
        $files['actions'] = ['entries' => [], 'format' => 2, 'registry' => 'actions'];

        return $files;
    }, 'actions.php', 'at format: format 2 is not format 1'],
    'the wrong registry' => [static function (array $files): array {
        $files['commands'] = ['entries' => [], 'format' => 1, 'registry' => 'actions'];

        return $files;
    }, 'commands.php', "at registry: it names the registry 'actions', not \"commands\""],
    'an extra key' => [static function (array $files): array {
        $files['slots'] = ['entries' => [], 'format' => 1, 'registry' => 'slots', 'built_at' => 1];

        return $files;
    }, 'slots.php', 'expected the keys entries, format, registry, got built_at, entries, format, registry'],
    'entries that are not a list' => [static function (array $files): array {
        $files['actions'] = ['entries' => ['a' => []], 'format' => 1, 'registry' => 'actions'];

        return $files;
    }, 'actions.php', 'at entries: expected a list, got array'],
    'an entry missing a key' => [static function (array $files): array {
        $files['commands'] = ['entries' => [['class' => 'App\C', 'name' => 'a.b', 'package' => 'acme/a']], 'format' => 1, 'registry' => 'commands'];

        return $files;
    }, 'commands.php', 'at entries[0]: expected the keys class, name, package, version, got class, name, package'],
    'a version that is a string' => [static function (array $files): array {
        $files['commands'] = ['entries' => [['class' => 'App\C', 'name' => 'a.b', 'package' => 'acme/a', 'version' => '1']], 'format' => 1, 'registry' => 'commands'];

        return $files;
    }, 'commands.php', 'at entries[0].version: expected an integer, got string'],
    'an unknown surface' => [static function (array $files): array {
        $files['actions'] = ['entries' => [['class' => 'App\A', 'package' => 'acme/a', 'surfaces' => ['rest', 'grpc']]], 'format' => 1, 'registry' => 'actions'];

        return $files;
    }, 'actions.php', "at entries[0].surfaces[1]: 'grpc' is not a surface"],
    'an unknown phase' => [static function (array $files): array {
        $entry = ['budget_ms' => 1, 'class' => 'App\H', 'command' => 'a.b', 'command_class' => 'App\C', 'command_version' => 1, 'package' => 'acme/a', 'phase' => 'commit', 'priority' => 0];
        $files['hooks'] = ['entries' => [$entry], 'format' => 1, 'registry' => 'hooks'];

        return $files;
    }, 'hooks.php', 'at entries[0].phase: "commit" is not a hook phase'],
    'a budget over the limit' => [static function (array $files): array {
        $entry = ['budget_ms' => 21, 'class' => 'App\H', 'command' => 'a.b', 'command_class' => 'App\C', 'command_version' => 1, 'package' => 'acme/a', 'phase' => 'validate', 'priority' => 0];
        $files['hooks'] = ['entries' => [$entry], 'format' => 1, 'registry' => 'hooks'];

        return $files;
    }, 'hooks.php', 'at entries[0]: Hook "App\H" has a budget of 21 ms'],
    'a class name that is not one' => [static function (array $files): array {
        $files['actions'] = ['entries' => [['class' => 'App\\', 'package' => 'acme/a', 'surfaces' => []]], 'format' => 1, 'registry' => 'actions'];

        return $files;
    }, 'actions.php', 'is not a fully qualified class name'],
    'a surface listed twice' => [static function (array $files): array {
        $files['actions'] = ['entries' => [['class' => 'App\A', 'package' => 'acme/a', 'surfaces' => ['mcp', 'mcp']]], 'format' => 1, 'registry' => 'actions'];

        return $files;
    }, 'actions.php', 'lists surface "mcp" more than once'],
    'a subscriber entry' => [static function (array $files): array {
        $files['subscribers'] = ['entries' => [['class' => 'App\S']], 'format' => 1, 'registry' => 'subscribers'];

        return $files;
    }, 'subscribers.php', 'format 1 has no subscribers entries'],
]);

it('knows how many entries each registry holds', function (): void {
    $registry = codecRegistry();

    expect(array_map($registry->count(...), RegistryName::cases()))->toBe([1, 1, 1, 0, 0, 0]);
});
