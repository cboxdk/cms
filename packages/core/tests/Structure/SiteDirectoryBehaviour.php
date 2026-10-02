<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SiteDirectory does, the fake and the Postgres one alike: no site before one is
 * registered, and a registered site by its id and by its handle, at version 1, with its root node
 * and its locales sorted by tag, and nothing for another id or handle.
 */
trait SiteDirectoryBehaviour
{
    public const string OTHER_SITE = '01936f5e-8a2b-7c3d-9e4f-0000000006a1';

    abstract protected function siteDirectory(): SiteDirectory;

    /**
     * Registers a site with the handle and locales, as site.register would, and gives its id and
     * root node.
     *
     * @param  list<Locale>  $locales
     * @return array{SiteId, NodeId}
     */
    abstract protected function registerSite(string $handle, array $locales): array;

    #[Test]
    public function it_finds_no_site_before_one_is_registered(): void
    {
        $directory = $this->siteDirectory();

        Assert::assertNull($directory->named(new SiteHandle('north')));
        Assert::assertNull($directory->find(SiteId::fromString(self::OTHER_SITE)));
    }

    #[Test]
    public function it_finds_a_registered_site_by_its_id_and_its_handle_with_its_locales_sorted(): void
    {
        [$site, $root] = $this->registerSite('north', [new Locale('en'), new Locale('da')]);
        $this->registerSite('south', [new Locale('da')]);
        $directory = $this->siteDirectory();

        foreach ([$directory->find($site), $directory->named(new SiteHandle('north'))] as $found) {
            Assert::assertInstanceOf(StoredSite::class, $found);
            Assert::assertSame(
                [$site->toString(), 'north', $root->toString(), 1, ['da', 'en']],
                [$found->id->toString(), $found->handle->value, $found->root->toString(), $found->version->value, StoredSite::tags($found->locales)],
            );
        }

        Assert::assertNull($directory->named(new SiteHandle('west')));
        Assert::assertNull($directory->find(SiteId::fromString(self::OTHER_SITE)));
        Assert::assertSame(['da'], StoredSite::tags($directory->named(new SiteHandle('south'))->locales ?? []));
    }
}
