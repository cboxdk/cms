<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Structure\Domain\SiteLocaleUse;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use UnexpectedValueException;

/**
 * SiteLocaleUse on Postgres: one statement through the owner lookup `cms_structure_locale_use`
 * (SECURITY DEFINER, see the migration that adds the site locale commands), which counts the
 * released placement locales in the locale below the site's root that are not withdrawn, past the
 * actor's regions, and raises 42501 without an actor context. It reads on the write PDO, inside
 * the command transaction, on the default connection or the one named.
 */
#[Internal]
final readonly class PostgresSiteLocaleUse implements SiteLocaleUse
{
    public const string PLACEMENTS = 'select cms_structure_locale_use(?::uuid, ?) as placements';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function placements(SiteId $site, Locale $locale): int
    {
        $count = $this->connections->connection($this->connection)->scalar(self::PLACEMENTS, [$site->toString(), $locale->value], false);

        if (! is_int($count)) {
            throw new UnexpectedValueException(sprintf('The placements of a site in a locale are counted as an integer, got %s.', get_debug_type($count)));
        }

        return $count;
    }
}
