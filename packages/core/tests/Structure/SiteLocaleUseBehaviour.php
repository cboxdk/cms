<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUse;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SiteLocaleUse of the kernel does (PRD 5.9, 11.14), held against the fake the site
 * locale action tests use and PostgresSiteLocaleUse, over one world the implementation's test
 * writes: the site SITE, publishing in da and en, with a live and a hidden placement in da below
 * its root and a withdrawn one, and the site OTHER, publishing in da, with a live placement in da
 * below its root. SITE has two placements in da, the withdrawn one not counted, none in en and
 * none in a locale it does not publish in; OTHER's placement counts for OTHER alone; a site that
 * does not exist has none.
 */
trait SiteLocaleUseBehaviour
{
    public const string SITE = '0192a0c0-0000-7000-8000-00000000c010';

    public const string OTHER = '0192a0c0-0000-7000-8000-00000000c011';

    public const string NOWHERE = '0192a0c0-0000-7000-8000-00000000c019';

    abstract protected function siteLocaleUse(): SiteLocaleUse;

    #[Test]
    public function it_counts_the_placements_of_the_site_in_the_locale_that_are_not_withdrawn(): void
    {
        $use = $this->siteLocaleUse();

        Assert::assertSame(2, $use->placements(SiteId::fromString(self::SITE), new Locale('da')));
        Assert::assertSame(0, $use->placements(SiteId::fromString(self::SITE), new Locale('en')));
        Assert::assertSame(0, $use->placements(SiteId::fromString(self::SITE), new Locale('de')));
    }

    #[Test]
    public function it_counts_the_placements_of_each_site_apart_and_none_for_a_site_that_does_not_exist(): void
    {
        $use = $this->siteLocaleUse();

        Assert::assertSame(1, $use->placements(SiteId::fromString(self::OTHER), new Locale('da')));
        Assert::assertSame(0, $use->placements(SiteId::fromString(self::NOWHERE), new Locale('da')));
    }
}
