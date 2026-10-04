<?php

declare(strict_types=1);

namespace Examples\Unit\Build;

use FilesystemIterator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * A Testbench application for testing a package's build declarations. The installed packages'
 * providers are discovered, as in an application, and WithWorkbench registers those of the
 * repository's testbench.yaml, which in cboxdk/cms's own repository, where cboxdk/cms is the root
 * package that discovery does not see, are cboxdk/cms's; so cboxdk/cms brings cms:build. The
 * bootstrap directory is a temporary directory of the test's own, so cms:build writes
 * bootstrap/cache/cms there and never into the Testbench skeleton, which other tests read; it is
 * removed after the test.
 */
abstract class BuildTestCase extends TestCase
{
    use WithWorkbench;

    /** Discover the service providers of the installed packages. */
    #[Override]
    protected $enablesPackageDiscoveries = true;

    private string $bootstrap = '';

    #[Override]
    protected function defineEnvironment($app): void
    {
        $this->bootstrap = sys_get_temp_dir().'/cms-build-example-'.bin2hex(random_bytes(8));
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
     * Registers the packages' service providers and runs cms:build. Returns its exit code, and
     * checks that it left the skeleton's bootstrap/cache/cms as it was.
     *
     * @param  class-string<ServiceProvider>  ...$providers
     */
    protected function build(string ...$providers): int
    {
        foreach ($providers as $provider) {
            app()->register($provider);
        }

        $skeleton = $this->skeletonCache();
        $status = app(Kernel::class)->call('cms:build');

        self::assertSame($skeleton, $this->skeletonCache(), 'cms:build changed the Testbench skeleton\'s bootstrap/cache/cms.');

        return $status;
    }

    /**
     * Adds the addons' Composer packages to the installation's allowlist of addons,
     * cbox-cms.addons.allowed (PRD 13.8), as an application does when it installs them; cms:build
     * refuses an addon that is not on it.
     */
    protected function allowAddons(string ...$packages): void
    {
        $allowed = config('cbox-cms.addons.allowed', []);

        config()->set('cbox-cms.addons.allowed', [...(is_array($allowed) ? $allowed : []), ...$packages]);
    }

    /**
     * Trusts the publisher's Ed25519 public key for the addon's panel bundle, as an installation
     * does in cbox-cms.addons.publishers after reviewing where the key came from.
     */
    protected function trustPublisher(string $package, string $publicKey): void
    {
        $publishers = config('cbox-cms.addons.publishers', []);

        config()->set('cbox-cms.addons.publishers', [...(is_array($publishers) ? $publishers : []), $package => [$publicKey]]);
    }

    /**
     * What the last cms:build printed.
     */
    protected function buildOutput(): string
    {
        return app(Kernel::class)->output();
    }

    /**
     * The directory cms:build writes to: bootstrap/cache/cms below the application's bootstrap path.
     */
    protected function registryDirectory(): string
    {
        return app()->bootstrapPath('cache/cms');
    }

    /**
     * The compiled file of a registry: actions, commands or hooks.
     */
    protected function registryFile(string $registry): string
    {
        return $this->registryDirectory().'/'.$registry.'.php';
    }

    /**
     * The sha1 of every file under the Testbench skeleton's bootstrap/cache/cms, by path.
     *
     * @return array<string, string>
     */
    private function skeletonCache(): array
    {
        $directory = default_skeleton_path('bootstrap/cache/cms');

        if ($directory === false) {
            return [];
        }

        $hashes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $hashes[$file->getPathname()] = sha1_file($file->getPathname()) ?: 'unreadable';
            }
        }

        ksort($hashes, SORT_STRING);

        return $hashes;
    }
}
