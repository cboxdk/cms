<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\VariantDerived;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Partitions\Adapter\MissingPartitionMapper;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Override;

/**
 * Writes VariantDerived in the commit (PRD 4, 9.2, 10.3): the derivation translated_from of the
 * revision in `derivations`, in the changeset, current until a release of the source marks it
 * stale (VariantReleasedWriter). The heads of both variants exist by then, because the revision and
 * its head are written before it in the same plan. It returns no event: the head's move tells
 * about the new revision (PRD 7.13). It runs as the app role under the call's actor context,
 * inside the command transaction.
 */
#[Internal]
final readonly class VariantDerivedWriter implements MutationWriter
{
    /** The kind of derivation a translation is (PRD 9.2). */
    public const string TRANSLATED_FROM = 'translated_from';

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
        return VariantDerived::class;
    }

    /**
     * @throws PartitionMissing when no partition of `derivations` covers the changeset
     */
    #[Override]
    public function write(Mutation $mutation, MutationContext $context): array
    {
        if (! $mutation instanceof VariantDerived) {
            throw new InvalidArgumentException(sprintf('The derivation writer writes VariantDerived, not %s.', $mutation::class));
        }

        try {
            $this->connections->connection($this->connection)->table('derivations')->insert([
                'changeset_id' => $context->changesetId->toString(),
                'entry_id' => $mutation->entry->toString(),
                'variant' => $mutation->variant->value,
                'rev_no' => $mutation->revision->value,
                'kind' => self::TRANSLATED_FROM,
                'source_variant' => $mutation->source->value,
                'source_rev_no' => $mutation->sourceRevision->value,
                'stale_changeset_id' => null,
                'created_at' => Timestamps::of($context->at),
            ]);
        } catch (QueryException $exception) {
            throw MissingPartitionMapper::map($exception);
        }

        return [];
    }
}
