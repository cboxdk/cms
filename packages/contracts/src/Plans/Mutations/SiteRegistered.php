<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\InvalidMutation;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A site is registered (PRD 5.8, 5.9, 11.14): the site with its handle, its root node, a new node
 * of kind site at the top of the tree, and the locales it publishes in, each with the route `/` to
 * the root node. The handle is a lower-case letter followed by at most 62 lower-case letters, digits
 * or underscores, as the configuration names the site; the locales are at least one, each once.
 */
#[Experimental]
final readonly class SiteRegistered implements Mutation
{
    /** The form of a site's handle. */
    public const string HANDLE_PATTERN = '/\A[a-z][a-z0-9_]{0,62}\z/';

    /**
     * @param  list<Locale>  $locales
     *
     * @throws InvalidMutation
     */
    public function __construct(
        public SiteId $site,
        public string $handle,
        public NodeId $root,
        public array $locales,
    ) {
        if (preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
            throw InvalidMutation::siteHandle($site, $handle);
        }

        if ($locales === []) {
            throw InvalidMutation::siteWithoutLocales($site);
        }

        $seen = [];

        foreach ($locales as $locale) {
            if (isset($seen[$locale->value])) {
                throw InvalidMutation::repeatedSiteLocale($site, $locale);
            }

            $seen[$locale->value] = true;
        }
    }

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->site;
    }
}
