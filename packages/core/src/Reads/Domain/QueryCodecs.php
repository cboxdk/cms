<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use InvalidArgumentException;

/**
 * The QueryCodec of each version of each query an exposed surface reads (GUARDRAILS 2.1, 2.2): the
 * codecs the core's service provider finds under the container tag TAG, one per name and version.
 * A query task adds its query's codecs by tagging them; an exposed surface cannot serve a query
 * without them.
 */
#[Experimental]
final readonly class QueryCodecs
{
    /** The container tag the codecs are registered under. */
    public const string TAG = 'cbox-cms.query-codecs';

    /** @var array<string, QueryCodec> by name and version */
    private array $codecs;

    /**
     * @throws InvalidArgumentException when two codecs read the same version of a query
     */
    public function __construct(QueryCodec ...$codecs)
    {
        $byKey = [];

        foreach ($codecs as $codec) {
            $key = $this->key($codec->name, $codec->version);

            if (isset($byKey[$key])) {
                throw new InvalidArgumentException(sprintf('Two codecs are registered for version %d of the query %s.', $codec->version, $codec->name->value));
            }

            $byKey[$key] = $codec;
        }

        $this->codecs = $byKey;
    }

    /**
     * @throws UnknownQuery when no codec reads that version of the query
     */
    public function for(CommandName $query, int $version): QueryCodec
    {
        return $this->find($query, $version) ?? throw UnknownQuery::noCodec($query->value, $version);
    }

    /**
     * The codecs of that version of the query, or null when none are registered.
     */
    public function find(CommandName $query, int $version): ?QueryCodec
    {
        return $this->codecs[$this->key($query, $version)] ?? null;
    }

    private function key(CommandName $query, int $version): string
    {
        return $query->value.'@'.$version;
    }
}
