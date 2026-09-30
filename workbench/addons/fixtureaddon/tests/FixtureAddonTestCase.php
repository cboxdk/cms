<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Override;

/**
 * The fixture addon's own Testbench application, as an addon tests itself against the kernel it
 * requires (PRD 13.7): the installed packages' providers are discovered, the addon's among them,
 * and WithWorkbench registers those of the repository's testbench.yaml, cboxdk/cms's and the
 * workbench's, which binds the generated type catalog. The bootstrap directory is a temporary
 * directory of the test's own, so cms:build writes bootstrap/cache/cms there and never into the
 * Testbench skeleton, which other tests read; it is removed after the test.
 */
abstract class FixtureAddonTestCase extends TestCase
{
    use WithWorkbench;

    /** Discover the service providers of the installed packages, the addon's among them. */
    #[Override]
    protected $enablesPackageDiscoveries = true;

    private string $bootstrap = '';

    #[Override]
    protected function defineEnvironment($app): void
    {
        $this->bootstrap = sys_get_temp_dir().'/cms-fixture-addon-'.bin2hex(random_bytes(8));
        mkdir($this->bootstrap, 0o700);

        $app->useBootstrapPath($this->bootstrap);
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        new Filesystem()->deleteDirectory($this->bootstrap);
    }

    /**
     * Runs cms:build and returns its exit code.
     */
    protected function build(): int
    {
        return app(Kernel::class)->call('cms:build');
    }

    /**
     * The entries of a compiled registry file: hooks or schema.
     *
     * @return list<array<string, mixed>>
     */
    protected function entries(string $registry): array
    {
        $compiled = require app()->bootstrapPath('cache/cms/'.$registry.'.php');
        self::assertIsArray($compiled);
        self::assertIsArray($compiled['entries']);

        $entries = [];

        foreach ($compiled['entries'] as $entry) {
            self::assertIsArray($entry);
            $entries[] = array_filter($entry, is_string(...), ARRAY_FILTER_USE_KEY);
        }

        return $entries;
    }
}
