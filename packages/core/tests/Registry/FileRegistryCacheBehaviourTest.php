<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryName;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * RegistryCacheBehaviour against FileRegistryCache in scratch directories. The unwritable cache
 * lies below a file, so its directory cannot be created, whoever runs the test.
 */
final class FileRegistryCacheBehaviourTest extends TestCase
{
    use RegistryCacheBehaviour;

    #[Override]
    protected function tearDown(): void
    {
        RegistryFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function registryCache(): RegistryCache
    {
        return RegistryFixtures::cache(RegistryFixtures::scratch());
    }

    #[Override]
    protected function unwritableRegistryCache(): RegistryCache
    {
        $directory = RegistryFixtures::scratch();
        mkdir($directory);
        file_put_contents($directory.'/bootstrap', 'a file where the directory should be');

        return RegistryFixtures::cache($directory.'/bootstrap/cache/cms');
    }

    #[Override]
    protected function damage(RegistryCache $cache): void
    {
        file_put_contents($cache->location().'/'.RegistryName::Commands->fileName(), "<?php return 'commands';\n");
    }
}
