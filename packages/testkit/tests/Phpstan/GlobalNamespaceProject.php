<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use RuntimeException;

/**
 * A scratch project in the system temp directory with the fixtures of Fixtures/GlobalNamespace
 * at the paths where an application or addon keeps them: a migration, a route file and a config
 * file, which are production code in the global namespace, and one Pest file below tests/ and
 * the same file outside it. The fixtures of the other rule tests sit below packages/testkit/tests,
 * so their global namespace is always below a tests directory.
 */
final readonly class GlobalNamespaceProject
{
    /**
     * Each file of the project and the fixture it is a copy of.
     *
     * @var array<string, string>
     */
    public const array FILES = [
        'config/things.php' => 'config',
        'database/migrations/2026_01_01_000000_create_things_table.php' => 'migration',
        'examples/ThingTest.php' => 'pest',
        'routes/web.php' => 'routes',
        'tests/Feature/ThingTest.php' => 'pest',
    ];

    private function __construct(public string $root) {}

    public static function create(): self
    {
        $temp = realpath(sys_get_temp_dir());

        if ($temp === false || in_array('tests', explode('/', $temp), true)) {
            throw new RuntimeException(sprintf('The system temp directory %s must exist and lie outside a directory named tests.', sys_get_temp_dir()));
        }

        $root = $temp.'/cms-global-namespace-'.bin2hex(random_bytes(4));

        foreach (self::FILES as $path => $fixture) {
            $file = $root.'/'.$path;

            if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0o755, true)) {
                throw new RuntimeException('Cannot create '.dirname($file));
            }

            if (! copy(__DIR__.'/Fixtures/GlobalNamespace/'.$fixture.'.php.inc', $file)) {
                throw new RuntimeException('Cannot write '.$file);
            }
        }

        return new self($root);
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return array_map(fn (string $path): string => $this->root.'/'.$path, array_keys(self::FILES));
    }

    public function relative(string $file): string
    {
        return str_starts_with($file, $this->root.'/') ? substr($file, strlen($this->root) + 1) : $file;
    }

    public function remove(): void
    {
        foreach (array_reverse($this->files()) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        foreach (['config', 'database/migrations', 'database', 'examples', 'routes', 'tests/Feature', 'tests', ''] as $directory) {
            if (is_dir($this->root.'/'.$directory)) {
                rmdir($this->root.'/'.$directory);
            }
        }
    }
}
