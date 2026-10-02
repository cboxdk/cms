<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Cbox\Cms\Core\Tests\Structure\Fakes\FakeSiteDirectory;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SiteDirectoryBehaviour against the fake the site action tests use.
 */
final class FakeSiteDirectoryBehaviourTest extends TestCase
{
    use SiteDirectoryBehaviour;

    private FakeSiteDirectory $directory {
        get => $this->directoryFake ??= new FakeSiteDirectory;
    }

    private ?FakeSiteDirectory $directoryFake = null;

    private FakeIdGenerator $ids {
        get => $this->idsFake ??= new FakeIdGenerator;
    }

    private ?FakeIdGenerator $idsFake = null;

    #[Override]
    protected function siteDirectory(): SiteDirectory
    {
        return $this->directory;
    }

    #[Override]
    protected function registerSite(string $handle, array $locales): array
    {
        $site = new SiteId($this->ids->next());
        $root = new NodeId($this->ids->next());
        $this->directory->add(new StoredSite($site, new SiteHandle($handle), $root, AggregateVersion::first(), $locales));

        return [$site, $root];
    }
}
