<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUse;
use Cbox\Cms\Core\Tests\Structure\Fakes\FakeSiteLocaleUse;
use Cbox\Cms\Tests\TestCase;
use Override;

/**
 * SiteLocaleUseBehaviour against the fake the site locale action tests use, over the same world.
 */
final class FakeSiteLocaleUseBehaviourTest extends TestCase
{
    use SiteLocaleUseBehaviour;

    #[Override]
    protected function siteLocaleUse(): SiteLocaleUse
    {
        return new FakeSiteLocaleUse()
            ->withPlacements(SiteId::fromString(self::SITE), new Locale('da'), 2)
            ->withPlacements(SiteId::fromString(self::OTHER), new Locale('da'), 1);
    }
}
