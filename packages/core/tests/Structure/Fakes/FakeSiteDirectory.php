<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure\Fakes;

use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Override;

/**
 * The SiteDirectory of the action tests: the sites a test adds, read by id or handle, with their
 * locales sorted by tag as the Postgres directory gives them. SiteDirectoryBehaviour holds it to
 * PostgresSiteDirectory.
 */
final class FakeSiteDirectory implements SiteDirectory
{
    /** @var list<StoredSite> */
    public array $sites = [];

    /** @var list<string> each read, as "id <uuid>" or "named <handle>" */
    public array $reads = [];

    public function add(StoredSite $site): StoredSite
    {
        $locales = $site->locales;
        usort($locales, static fn ($a, $b): int => strcmp($a->value, $b->value));
        $stored = new StoredSite($site->id, $site->handle, $site->root, $site->version, $locales);
        $this->sites[] = $stored;

        return $stored;
    }

    #[Override]
    public function find(SiteId $site): ?StoredSite
    {
        $this->reads[] = 'id '.$site->toString();

        return array_find($this->sites, static fn (StoredSite $stored): bool => $stored->id->equals($site));
    }

    #[Override]
    public function named(SiteHandle $handle): ?StoredSite
    {
        $this->reads[] = 'named '.$handle->value;

        return array_find($this->sites, static fn (StoredSite $stored): bool => $stored->handle->equals($handle));
    }
}
