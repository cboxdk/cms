<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Core\Entries\Domain\Dto\LocaleFields;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededIdentity;
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
 * releasable type is released in releasedPercent of the cases. An entry of a type with localization
 * variants gets a variant in each of the locales given, in their order, its fields from the type's
 * rules for a language's variant (PRD 10.1); such a type needs at least one locale. A relation
 * names entries seeded before it (EarlierEntries), so its targets exist when it is written.
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
     * @param  list<Locale>  $locales  the languages of an entry of a type with localization variants
     *
     * @throws InvalidArgumentException for no types, no nodes, a negative seed, or a type with localization variants without a locale
     */
    public function __construct(
        private SeedProfile $profile,
        private int $seed,
        private array $types,
        private array $nodes,
        private ClassificationAccess $access,
        private array $locales = [],
    ) {
        if ($types === [] || $nodes === [] || $seed < 0) {
            throw new InvalidArgumentException('An entry generator needs a seed of 0 or more, at least one seedable type and at least one node.');
        }

        foreach ($types as $type) {
            if ($type->languageRules instanceof TypeRules && $locales === []) {
                throw new InvalidArgumentException(sprintf('The type %s has localization variants, so an entry of it needs a language, and no locale was given.', $type->definition->name->value));
            }
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

        $random = $this->randomizer($index);
        $identity = $this->identityFrom($random, $index);
        $type = $identity->type;
        $relations = new EarlierEntries($this, $identity, $index);
        $home = $this->nodes[$this->nodeSpread->pick($random)];
        $release = $type->releasable && $random->getInt(1, 100) <= $this->profile->releasedPercent;
        $shared = $this->fields->fields($type, $this->access, $random, relations: $relations);
        $languages = [];

        if ($type->languageRules instanceof TypeRules) {
            foreach ($this->locales as $locale) {
                $languages[] = new LocaleFields($locale, $this->fields->fields($type, $this->access, $random, $type->languageRules, $relations));
            }
        }

        return new SeededEntry($identity->id, $type->definition->id, $home, $shared, $release, $languages);
    }

    /**
     * The id and the type of entry n, without its fields: the first draws of its randomizer, as
     * entry() makes them. EarlierEntries reads the targets of a relation with it.
     */
    public function identity(int $index): SeededIdentity
    {
        return $this->identityFrom($this->randomizer($index), $index);
    }

    private function randomizer(int $index): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash('sha256', sprintf('%s:%d:%d', $this->profile->label(), $this->seed, $index), true)));
    }

    private function identityFrom(Randomizer $random, int $index): SeededIdentity
    {
        $id = new EntryId(Uuid7::generate($this->epoch + $index, $random));

        return new SeededIdentity($id, $this->types[$this->typeMix->pick($random)]);
    }
}
