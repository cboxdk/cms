<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * A site's use of a locale (PRD 5.9, 11.14), as an aggregate a command reads so a locale is never
 * retired under a placement: it exists, at version 1, while a released placement locale in the
 * locale below the site's root is not withdrawn, and is absent otherwise. site.remove_locale reads
 * it as absent, and the commit, which locks an aggregate read as absent with an advisory lock
 * first, after it has locked the site's row that placement.create shares, finds it taken when a
 * placement was created in the locale meanwhile, which is version_conflict instead of an orphaned
 * placement.
 */
#[Internal]
final readonly class SiteLocaleUseRef implements AggregateRef
{
    public const string KIND = 'site_locale_use';

    public function __construct(
        public SiteId $site,
        public Locale $locale,
    ) {}

    /**
     * "site_locale_use:", the site and the locale, separated by colons; neither holds a colon.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return sprintf('%s:%s:%s', self::KIND, $this->site->toString(), $this->locale->value);
    }
}
