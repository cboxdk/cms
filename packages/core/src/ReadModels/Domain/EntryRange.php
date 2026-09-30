<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use InvalidArgumentException;

/**
 * The entries of one chunk of a rebuild (PRD 4.1, invariant 22): the entries of the type whose ids
 * lie from $first to $last, both included, in the order Postgres sorts uuids.
 *
 * A range is the chunk's name, `entries:<first>:<last>`, so an operation that resumes reads the
 * range back from the chunk it stored (chunk() and fromChunk()), whatever entries were created
 * since. An entry created later with an id inside the range of a chunk that already ran was written
 * by its command, whose writer writes its type row too.
 */
#[Experimental]
final readonly class EntryRange
{
    private const string PREFIX = 'entries';

    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * @throws InvalidArgumentException when $last sorts before $first
     */
    public function __construct(
        public EntryId $first,
        public EntryId $last,
    ) {
        if (strcmp($first->toString(), $last->toString()) > 0) {
            throw new InvalidArgumentException(sprintf('A range of entries runs from its first id to its last, and %s sorts after %s.', $first->toString(), $last->toString()));
        }
    }

    /**
     * The range a chunk of a rebuild names.
     *
     * @throws InvalidArgumentException when the chunk does not name a range of entries
     */
    public static function fromChunk(ChunkName $chunk): self
    {
        if (preg_match('/\A'.self::PREFIX.':(?<first>'.self::UUID.'):(?<last>'.self::UUID.')\z/', $chunk->value, $ids) !== 1) {
            throw new InvalidArgumentException(sprintf('A chunk of a rebuild is named %s:<first entry id>:<last entry id>, got "%s".', self::PREFIX, $chunk->value));
        }

        return new self(EntryId::fromString($ids['first']), EntryId::fromString($ids['last']));
    }

    /**
     * The chunk's name, `entries:<first>:<last>`.
     */
    public function chunk(): ChunkName
    {
        return new ChunkName(self::PREFIX.':'.$this->first->toString().':'.$this->last->toString());
    }
}
