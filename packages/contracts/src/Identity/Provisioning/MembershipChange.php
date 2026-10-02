<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * A PATCH of a SCIM group's members (RFC 7644 3.5.2): the users to add and the users to remove, each
 * the id of a user of the group's connection. At least one, each once, and none both added and
 * removed. Adding a member the group has, or removing one it lacks, changes nothing.
 */
#[Experimental]
final readonly class MembershipChange
{
    /** @var list<ScimResourceId> sorted by id */
    public array $add;

    /** @var list<ScimResourceId> sorted by id */
    public array $remove;

    /**
     * @param  list<ScimResourceId>  $add
     * @param  list<ScimResourceId>  $remove
     *
     * @throws InvalidIdentity when both are empty, one names a user twice, or a user is in both
     */
    public function __construct(array $add = [], array $remove = [])
    {
        $this->add = self::sorted($add, 'members to add');
        $this->remove = self::sorted($remove, 'members to remove');

        if ($this->add === [] && $this->remove === []) {
            throw InvalidIdentity::signalValue('membership change', 'at least one member to add or remove');
        }

        if (array_intersect($this->values($this->add), $this->values($this->remove)) !== []) {
            throw InvalidIdentity::signalValue('membership change', 'a change that does not both add and remove one user');
        }
    }

    /**
     * The members after the change is applied to the given ones, sorted by id.
     *
     * @param  list<ScimResourceId>  $members
     * @return list<ScimResourceId>
     */
    public function applyTo(array $members): array
    {
        $removed = $this->values($this->remove);
        $after = [];

        foreach ([...$members, ...$this->add] as $member) {
            if (! in_array($member->value, $removed, true)) {
                $after[$member->value] = $member;
            }
        }

        return self::sorted(array_values($after), 'members');
    }

    /**
     * The ids sorted, each once.
     *
     * @param  list<ScimResourceId>  $ids
     * @return list<ScimResourceId>
     *
     * @throws InvalidIdentity when an id is given twice
     */
    public static function sorted(array $ids, string $what): array
    {
        $byValue = [];

        foreach ($ids as $id) {
            if (isset($byValue[$id->value])) {
                throw InvalidIdentity::signalValue($what, 'a list that names each user once');
            }

            $byValue[$id->value] = $id;
        }

        ksort($byValue, SORT_STRING);

        return array_values($byValue);
    }

    /**
     * @param  list<ScimResourceId>  $ids
     * @return list<string>
     */
    private function values(array $ids): array
    {
        return array_map(static fn (ScimResourceId $id): string => $id->value, $ids);
    }
}
