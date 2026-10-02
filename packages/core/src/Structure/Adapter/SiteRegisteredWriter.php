<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\SiteRegistered;
use Cbox\Cms\Core\Entries\Adapter\Timestamps;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Structure\Domain\Events\SiteRegistered as SiteRegisteredEvent;
use Cbox\Cms\Core\Structure\Domain\Events\SiteRegisteredV1;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use Override;

/**
 * Writes SiteRegistered in the commit (PRD 5.8, 5.9, 6.2 phase 7): the root node, a node of kind
 * site at the top of the tree at version 1, the site at the context's version, which is 1 for the
 * site the command read as absent, its locales, and in each locale the route `/` to the root node,
 * all at the changeset's time. It returns site.registered.
 *
 * The app role writes none of the structure tables, so the writer calls REGISTER, which runs as the
 * owner role and only in the transaction of a site.register changeset by the context's actor (see
 * the migration that adds it). It runs on the default connection, or the one named, inside the
 * command transaction, after the commit has locked the site's id and handle.
 */
#[Internal]
final readonly class SiteRegisteredWriter implements MutationWriter
{
    /** The registration of one site with its root node, locales and root routes, as the owner role. */
    public const string REGISTER = 'select cms_structure_register_site(?::uuid, ?, ?::uuid, ?::text[], ?::bigint, ?::timestamptz, ?::uuid)';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ?string $connection = null,
    ) {}

    #[Override]
    public function writes(): string
    {
        return SiteRegistered::class;
    }

    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof SiteRegistered) {
            throw new InvalidArgumentException(sprintf('The site registration writer writes SiteRegistered, not %s.', $mutation::class));
        }

        $this->connections->connection($this->connection)->statement(self::REGISTER, [
            $mutation->site->toString(),
            $mutation->handle,
            $mutation->root->toString(),
            '{'.implode(',', array_map(static fn (Locale $locale): string => $locale->value, $mutation->locales)).'}',
            $context->version->value,
            Timestamps::of($context->at),
            $context->changesetId->toString(),
        ]);

        return [new SiteRegisteredEvent($context->version->value, new SiteRegisteredV1($mutation->site, $mutation->root, $mutation->locales))];
    }
}
