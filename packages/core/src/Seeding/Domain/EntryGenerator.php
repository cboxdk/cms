<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The entries of a seeded data set (GUARDRAILS 4.3), one at a time by index. Entry n depends only on
 * the profile, the seed, n, the seedable types and the nodes, never on how many entries the run
 * seeds or how they are chunked: its randomizer is seeded with the SHA-256 of the profile's label,
 * the seed and n. So the same seed gives the same entries in every run, and a larger run starts with
 * the entries of a smaller one.
 *
 * An entry's id is a UUIDv7 whose time is the profile's anchor plus n milliseconds and whose random
 * bits come from the entry's randomizer, so ids are unique, rise with n and are the same in every
 * run. Its type follows the profile's type skew over the types (sorted by name), its home node the
 * node skew over the nodes (sorted by id), its fields the FieldValueGenerator, and an entry of a
 * releasable type is released in releasedPercent of the cases.
 */
#[Internal]
final readonly class EntryGenerator
{
    private ZipfDistribution $typeMix;

    private ZipfDistribution $nodeSpread;

    private FieldValueGenerator $fields;

    private int $epoch;

    /**
     * @param  list<SeedableType>  $types
     * @param  list<NodeId>  $nodes
     *
     * @throws InvalidArgumentException for no types, no nodes or a negative seed
     */
    public function __construct(
        private SeedProfile $profile,
        private int $seed,
        private array $types,
        private array $nodes,
        private ClassificationAccess $access,
    ) {
        if ($types === [] || $nodes === [] || $seed < 0) {
            throw new InvalidArgumentException('An entry generator needs a seed of 0 or more, at least one seedable type and at least one node.');
        }

        $this->typeMix = new ZipfDistribution(count($types), $profile->typeSkew);
        $this->nodeSpread = new ZipfDistribution(count($nodes), $profile->nodeSkew);
        $this->fields = new FieldValueGenerator($profile);
        $this->epoch = Uuid7::unixMillisecondsOf($profile->anchor);
    }

    public function entry(int $index): SeededEntry
    {
        if ($index < 0 || $index >= SeedRequestLimits::MAX_ENTRIES) {
            throw new InvalidArgumentException(sprintf('An entry index is 0 to %d, got %d.', SeedRequestLimits::MAX_ENTRIES - 1, $index));
        }

        $random = new Randomizer(new Xoshiro256StarStar(hash('sha256', sprintf('%s:%d:%d', $this->profile->label(), $this->seed, $index), true)));
        $id = new EntryId(Uuid7::generate($this->epoch + $index, $random));
        $type = $this->types[$this->typeMix->pick($random)];
        $home = $this->nodes[$this->nodeSpread->pick($random)];
        $release = $type->releasable && $random->getInt(1, 100) <= $this->profile->releasedPercent;

        return new SeededEntry($id, $type->definition->id, $home, $this->fields->fields($type, $this->access, $random), $release);
    }
}
