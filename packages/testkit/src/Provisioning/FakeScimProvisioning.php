<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Provisioning\MembershipChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ResourceVersion;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimDeletion;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimErrorCode;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimGroup;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimGroupOutcome;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimGroupResource;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimIdempotencyKey;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimProvisioning;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimRefused;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimResourceId;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimResourceType;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUser;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserOutcome;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserResource;
use Override;

/**
 * The in-memory fake of ScimProvisioning (GUARDRAILS 2.3), and its own harness. It holds the users
 * and groups of every connection, gives ids user-<n> and group-<n>, and records which source
 * deactivated each user, so only the connection that deactivated a user reactivates it. It runs no
 * command; an outcome says which ones the real SCIM server, which comes with B6, commits.
 */
#[Experimental]
final class FakeScimProvisioning implements ScimProvisioning, ScimProvisioningHarness
{
    /** @var array<string, ScimUserResource> by id */
    private array $users = [];

    /** @var array<string, ScimGroupResource> by id */
    private array $groups = [];

    /** @var array<string, true> the ids of the users another source than SCIM deactivated */
    private array $deactivatedElsewhere = [];

    private int $created = 0;

    #[Override]
    public function provisioning(): ScimProvisioning
    {
        return $this;
    }

    #[Override]
    public function deactivateElsewhere(ConnectionId $connection, ScimResourceId $user): void
    {
        $current = $this->user($connection, $user);

        if (! $current->user->active) {
            return;
        }

        $this->users[$user->value] = new ScimUserResource($user, $connection, $current->user->withActive(false), $current->version->next());
        $this->deactivatedElsewhere[$user->value] = true;
    }

    #[Override]
    public function createUser(ConnectionId $connection, ScimUser $user): ScimUserOutcome
    {
        foreach ($this->usersOf($connection) as $existing) {
            if ($existing->user->externalId->equals($user->externalId) && $existing->user->equals($user)) {
                return new ScimUserOutcome($existing, [], null);
            }

            if ($existing->user->externalId->equals($user->externalId) || $existing->user->userName->sameAs($user->userName)) {
                throw ScimRefused::because(ScimErrorCode::Uniqueness);
            }
        }

        $id = $this->nextId('user');
        $resource = new ScimUserResource($id, $connection, $user, ResourceVersion::first());
        $this->users[$id->value] = $resource;

        return new ScimUserOutcome($resource, [ScimChange::Created], new ScimIdempotencyKey($connection, ScimResourceType::User, $id, $user->canonical(), null));
    }

    #[Override]
    public function replaceUser(ConnectionId $connection, ScimResourceId $id, ScimUser $user, ?ResourceVersion $ifMatch = null): ScimUserOutcome
    {
        $current = $this->user($connection, $id);
        $this->match($current->version, $ifMatch);

        if (! $current->user->externalId->equals($user->externalId)) {
            throw ScimRefused::because(ScimErrorCode::Mutability);
        }

        foreach ($this->usersOf($connection) as $other) {
            if (! $other->id->equals($id) && $other->user->userName->sameAs($user->userName)) {
                throw ScimRefused::because(ScimErrorCode::Uniqueness);
            }
        }

        if ($current->user->equals($user)) {
            return new ScimUserOutcome($current, [], null);
        }

        $reactivates = ! $current->user->active && $user->active;

        if ($reactivates && isset($this->deactivatedElsewhere[$id->value])) {
            throw ScimRefused::because(ScimErrorCode::ReactivationRefused);
        }

        $changes = [];

        if (! $current->user->equals($user->withActive($current->user->active))) {
            $changes[] = ScimChange::Replaced;
        }

        if ($current->user->active !== $user->active) {
            $changes[] = $user->active ? ScimChange::Reactivated : ScimChange::Deactivated;
        }

        $resource = new ScimUserResource($id, $connection, $user, $current->version->next());
        $this->users[$id->value] = $resource;

        return new ScimUserOutcome($resource, $changes, new ScimIdempotencyKey($connection, ScimResourceType::User, $id, $user->canonical(), $current->version));
    }

    #[Override]
    public function setUserActive(ConnectionId $connection, ScimResourceId $id, bool $active, ?ResourceVersion $ifMatch = null): ScimUserOutcome
    {
        $current = $this->user($connection, $id);

        return $this->replaceUser($connection, $id, $current->user->withActive($active), $ifMatch);
    }

    #[Override]
    public function deleteUser(ConnectionId $connection, ScimResourceId $id, ?ResourceVersion $ifMatch = null): ScimDeletion
    {
        $current = $this->user($connection, $id);
        $this->match($current->version, $ifMatch);

        unset($this->users[$id->value], $this->deactivatedElsewhere[$id->value]);

        foreach ($this->groupsOf($connection) as $group) {
            $members = array_values(array_filter($group->group->members, static fn (ScimResourceId $member): bool => ! $member->equals($id)));

            if (count($members) !== count($group->group->members)) {
                $this->groups[$group->id->value] = new ScimGroupResource($group->id, $connection, $group->group->withMembers($members), $group->version->next());
            }
        }

        return new ScimDeletion(ScimResourceType::User, $id, new ScimIdempotencyKey($connection, ScimResourceType::User, $id, ScimIdempotencyKey::DELETED, $current->version));
    }

    #[Override]
    public function user(ConnectionId $connection, ScimResourceId $id): ScimUserResource
    {
        $user = $this->users[$id->value] ?? null;

        if ($user === null || ! $user->connection->equals($connection)) {
            throw ScimRefused::because(ScimErrorCode::ResourceNotFound);
        }

        return $user;
    }

    #[Override]
    public function createGroup(ConnectionId $connection, ScimGroup $group): ScimGroupOutcome
    {
        $this->checkMembers($connection, $group->members);

        foreach ($this->groupsOf($connection) as $existing) {
            if ($existing->group->sameNameAs($group)) {
                if ($existing->group->equals($group)) {
                    return new ScimGroupOutcome($existing, [], null);
                }

                throw ScimRefused::because(ScimErrorCode::Uniqueness);
            }
        }

        $id = $this->nextId('group');
        $resource = new ScimGroupResource($id, $connection, $group, ResourceVersion::first());
        $this->groups[$id->value] = $resource;

        return new ScimGroupOutcome($resource, [ScimChange::Created], new ScimIdempotencyKey($connection, ScimResourceType::Group, $id, $group->canonical(), null));
    }

    #[Override]
    public function replaceGroup(ConnectionId $connection, ScimResourceId $id, ScimGroup $group, ?ResourceVersion $ifMatch = null): ScimGroupOutcome
    {
        $current = $this->group($connection, $id);
        $this->match($current->version, $ifMatch);
        $this->checkMembers($connection, $group->members);

        if (! $this->sameExternalId($current->group, $group)) {
            throw ScimRefused::because(ScimErrorCode::Mutability);
        }

        foreach ($this->groupsOf($connection) as $other) {
            if (! $other->id->equals($id) && $other->group->sameNameAs($group)) {
                throw ScimRefused::because(ScimErrorCode::Uniqueness);
            }
        }

        return $this->changeGroup($current, $group);
    }

    #[Override]
    public function changeMembers(ConnectionId $connection, ScimResourceId $id, MembershipChange $change, ?ResourceVersion $ifMatch = null): ScimGroupOutcome
    {
        $current = $this->group($connection, $id);
        $this->match($current->version, $ifMatch);
        $this->checkMembers($connection, [...$change->add, ...$change->remove]);

        return $this->changeGroup($current, $current->group->withMembers($change->applyTo($current->group->members)));
    }

    #[Override]
    public function deleteGroup(ConnectionId $connection, ScimResourceId $id, ?ResourceVersion $ifMatch = null): ScimDeletion
    {
        $current = $this->group($connection, $id);
        $this->match($current->version, $ifMatch);

        unset($this->groups[$id->value]);

        return new ScimDeletion(ScimResourceType::Group, $id, new ScimIdempotencyKey($connection, ScimResourceType::Group, $id, ScimIdempotencyKey::DELETED, $current->version));
    }

    #[Override]
    public function group(ConnectionId $connection, ScimResourceId $id): ScimGroupResource
    {
        $group = $this->groups[$id->value] ?? null;

        if ($group === null || ! $group->connection->equals($connection)) {
            throw ScimRefused::because(ScimErrorCode::ResourceNotFound);
        }

        return $group;
    }

    private function changeGroup(ScimGroupResource $current, ScimGroup $group): ScimGroupOutcome
    {
        if ($current->group->equals($group)) {
            return new ScimGroupOutcome($current, [], null);
        }

        $changes = [];

        if (! $current->group->equals($group->withMembers($current->group->members))) {
            $changes[] = ScimChange::Replaced;
        }

        if (! $current->group->withMembers($group->members)->equals($current->group)) {
            $changes[] = ScimChange::MembersChanged;
        }

        $resource = new ScimGroupResource($current->id, $current->connection, $group, $current->version->next());
        $this->groups[$current->id->value] = $resource;

        return new ScimGroupOutcome($resource, $changes, new ScimIdempotencyKey($current->connection, ScimResourceType::Group, $current->id, $group->canonical(), $current->version));
    }

    /**
     * @param  list<ScimResourceId>  $members
     */
    private function checkMembers(ConnectionId $connection, array $members): void
    {
        foreach ($members as $member) {
            $user = $this->users[$member->value] ?? null;

            if ($user === null || ! $user->connection->equals($connection)) {
                throw ScimRefused::because(ScimErrorCode::InvalidValue);
            }
        }
    }

    /**
     * @return list<ScimUserResource>
     */
    private function usersOf(ConnectionId $connection): array
    {
        return array_values(array_filter($this->users, static fn (ScimUserResource $user): bool => $user->connection->equals($connection)));
    }

    /**
     * @return list<ScimGroupResource>
     */
    private function groupsOf(ConnectionId $connection): array
    {
        return array_values(array_filter($this->groups, static fn (ScimGroupResource $group): bool => $group->connection->equals($connection)));
    }

    private function nextId(string $kind): ScimResourceId
    {
        $this->created++;

        return new ScimResourceId(sprintf('%s-%d', $kind, $this->created));
    }

    private function match(ResourceVersion $current, ?ResourceVersion $ifMatch): void
    {
        if ($ifMatch instanceof ResourceVersion && ! $ifMatch->equals($current)) {
            throw ScimRefused::because(ScimErrorCode::VersionMismatch);
        }
    }

    private function sameExternalId(ScimGroup $current, ScimGroup $group): bool
    {
        return $current->externalId instanceof Subject
            ? $group->externalId instanceof Subject && $current->externalId->equals($group->externalId)
            : ! $group->externalId instanceof Subject;
    }
}
