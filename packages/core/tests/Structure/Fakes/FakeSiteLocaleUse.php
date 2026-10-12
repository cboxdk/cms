<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure\Fakes;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUse;
use Override;

/**
 * The SiteLocaleUse of the action tests: the placements a test says a site has in a locale, by
 * site and locale, none unless said. SiteLocaleUseBehaviour holds it to PostgresSiteLocaleUse.
 */
final class FakeSiteLocaleUse implements SiteLocaleUse
{
    /** @var array<string, int> by "<site>|<locale>" */
    public array $counts = [];

    /** @var list<string> each read, as "<site> <locale>" */
    public array $reads = [];

    public function withPlacements(SiteId $site, Locale $locale, int $placements): self
    {
        $this->counts[$site->toString().'|'.$locale->value] = $placements;

        return $this;
    }

    #[Override]
    public function placements(SiteId $site, Locale $locale): int
    {
        $this->reads[] = $site->toString().' '.$locale->value;

        return $this->counts[$site->toString().'|'.$locale->value] ?? 0;
    }
}
