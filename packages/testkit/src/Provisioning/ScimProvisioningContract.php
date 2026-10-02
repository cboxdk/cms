<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Provisioning\MembershipChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ResourceVersion;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimChange;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimErrorCode;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimGroup;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimIdempotencyKey;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimRefused;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimResourceId;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimResourceType;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUser;
use Cbox\Cms\Contracts\Identity\Provisioning\ScimUserName;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for ScimProvisioning (GUARDRAILS 2.3 and 9, PRD 5.16). The fake and
 * every real SCIM server run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness with an empty provisioning:
 *
 *     final class FakeScimProvisioningContractTest extends TestCase
 *     {
 *         use ScimProvisioningContract;
 *
 *         protected function scim(): ScimProvisioningHarness
 *         {
 *             return new FakeScimProvisioning;
 *         }
 *     }
 *
 * The cases cover creating, reading, replacing, patching active and deleting users and groups; a
 * repeated request, which has one effect; the idempotency key, a new deactivation after a
 * reactivation and its version; that a connection's token touches only its own resources and
 * members; uniqueness, the immutable externalId, If-Match, and a reactivation refused for an actor
 * another source deactivated.
 */
#[Experimental]
trait ScimProvisioningContract
{
    /**
     * A harness whose provisioning has no users or groups.
     */
    abstract protected function scim(): ScimProvisioningHarness;

    #[Test]
    public function a_created_user_is_read_back_at_its_first_version(): void
    {
        $scim = $this->scim()->provisioning();
        $ada = $this->ada();

        $created = $scim->createUser($this->acme(), $ada);
        $read = $scim->user($this->acme(), $created->user->id);

        Assert::assertSame([ScimChange::Created], $created->changes);
        Assert::assertTrue($created->user->connection->equals($this->acme()));
        Assert::assertTrue($created->user->user->equals($ada));
        Assert::assertTrue($created->user->version->equals(ResourceVersion::first()));
        Assert::assertNotNull($created->key);
        Assert::assertTrue($created->key->equals(new ScimIdempotencyKey($this->acme(), ScimResourceType::User, $created->user->id, $ada->canonical(), null)));
        Assert::assertTrue($read->id->equals($created->user->id));
        Assert::assertTrue($read->user->equals($ada));
        Assert::assertTrue($read->version->equals(ResourceVersion::first()));
    }

    #[Test]
    public function a_repeated_create_has_one_effect(): void
    {
        $scim = $this->scim()->provisioning();
        $first = $scim->createUser($this->acme(), $this->ada());

        $again = $scim->createUser($this->acme(), $this->ada());

        Assert::assertFalse($again->changed());
        Assert::assertNull($again->key);
        Assert::assertTrue($again->user->id->equals($first->user->id));
        Assert::assertTrue($again->user->version->equals(ResourceVersion::first()));
    }

    #[Test]
    public function a_user_is_unique_in_its_connection_by_external_id_and_user_name(): void
    {
        $scim = $this->scim()->provisioning();
        $scim->createUser($this->acme(), $this->ada());

        $this->expectRefusal(ScimErrorCode::Uniqueness, fn () => $scim->createUser($this->acme(), $this->ada(displayName: 'Ada King')));
        $this->expectRefusal(ScimErrorCode::Uniqueness, fn () => $scim->createUser($this->acme(), $this->user('00u-other', 'ADA@example.org')));
    }

    #[Test]
    public function two_connections_each_provision_their_own_user_for_one_external_id(): void
    {
        $scim = $this->scim()->provisioning();

        $acme = $scim->createUser($this->acme(), $this->ada());
        $globex = $scim->createUser($this->globex(), $this->ada());

        Assert::assertTrue($globex->changed());
        Assert::assertFalse($acme->user->id->equals($globex->user->id));
        Assert::assertTrue($scim->user($this->globex(), $globex->user->id)->connection->equals($this->globex()));
    }

    #[Test]
    public function a_call_for_another_connections_resource_is_refused(): void
    {
        $scim = $this->scim()->provisioning();
        $user = $scim->createUser($this->acme(), $this->ada())->user;
        $group = $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('Editors'), null, [$user->id]))->group;
        $other = $this->globex();

        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->user($other, $user->id));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->replaceUser($other, $user->id, $this->ada(displayName: 'Taken over')));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->setUserActive($other, $user->id, false));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->deleteUser($other, $user->id));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->group($other, $group->id));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->replaceGroup($other, $group->id, new ScimGroup(new DisplayName('Taken over'))));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->changeMembers($other, $group->id, new MembershipChange(remove: [$user->id])));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->deleteGroup($other, $group->id));

        $after = $scim->user($this->acme(), $user->id);
        $groupAfter = $scim->group($this->acme(), $group->id);

        Assert::assertTrue($after->user->equals($this->ada()));
        Assert::assertTrue($after->version->equals(ResourceVersion::first()));
        Assert::assertTrue($groupAfter->version->equals(ResourceVersion::first()));
        Assert::assertCount(1, $groupAfter->group->members);
    }

    #[Test]
    public function a_member_that_is_not_a_user_of_the_connection_is_refused(): void
    {
        $scim = $this->scim()->provisioning();
        $acmeUser = $scim->createUser($this->acme(), $this->ada())->user;
        $globexGroup = $scim->createGroup($this->globex(), new ScimGroup(new DisplayName('Editors')))->group;

        $this->expectRefusal(ScimErrorCode::InvalidValue, fn () => $scim->createGroup($this->globex(), new ScimGroup(new DisplayName('Authors'), null, [$acmeUser->id])));
        $this->expectRefusal(ScimErrorCode::InvalidValue, fn () => $scim->changeMembers($this->globex(), $globexGroup->id, new MembershipChange(add: [$acmeUser->id])));
        $this->expectRefusal(ScimErrorCode::InvalidValue, fn () => $scim->replaceGroup($this->globex(), $globexGroup->id, new ScimGroup(new DisplayName('Editors'), null, [$acmeUser->id])));
        $this->expectRefusal(ScimErrorCode::InvalidValue, fn () => $scim->changeMembers($this->globex(), $globexGroup->id, new MembershipChange(add: [new ScimResourceId('no-such-user')])));

        Assert::assertSame([], $scim->group($this->globex(), $globexGroup->id)->group->members);
    }

    #[Test]
    public function a_repeated_deactivation_has_one_effect(): void
    {
        $scim = $this->scim()->provisioning();
        $id = $scim->createUser($this->acme(), $this->ada())->user->id;

        $first = $scim->setUserActive($this->acme(), $id, false);
        $again = $scim->setUserActive($this->acme(), $id, false);
        $put = $scim->replaceUser($this->acme(), $id, $this->ada()->withActive(false));

        Assert::assertSame([ScimChange::Deactivated], $first->changes);
        Assert::assertFalse($first->user->user->active);
        Assert::assertTrue($first->user->version->equals(new ResourceVersion(2)));
        Assert::assertFalse($again->changed());
        Assert::assertNull($again->key);
        Assert::assertTrue($again->user->version->equals(new ResourceVersion(2)));
        Assert::assertFalse($put->changed());
        Assert::assertTrue($scim->user($this->acme(), $id)->version->equals(new ResourceVersion(2)));
    }

    #[Test]
    public function the_key_is_the_connection_the_resource_the_desired_state_and_the_version(): void
    {
        $scim = $this->scim()->provisioning();
        $id = $scim->createUser($this->acme(), $this->ada())->user->id;

        $deactivated = $scim->setUserActive($this->acme(), $id, false);

        Assert::assertNotNull($deactivated->key);
        Assert::assertTrue($deactivated->key->connection->equals($this->acme()));
        Assert::assertSame(ScimResourceType::User, $deactivated->key->type);
        Assert::assertTrue($deactivated->key->resource->equals($id));
        Assert::assertNotNull($deactivated->key->version);
        Assert::assertTrue($deactivated->key->version->equals(ResourceVersion::first()));
        Assert::assertTrue($deactivated->key->equals(new ScimIdempotencyKey($this->acme(), ScimResourceType::User, $id, $this->ada()->withActive(false)->canonical(), ResourceVersion::first())));
    }

    #[Test]
    public function a_deactivation_after_a_reactivation_is_a_new_change(): void
    {
        $scim = $this->scim()->provisioning();
        $id = $scim->createUser($this->acme(), $this->ada())->user->id;

        $first = $scim->setUserActive($this->acme(), $id, false);
        $reactivated = $scim->setUserActive($this->acme(), $id, true);
        $second = $scim->setUserActive($this->acme(), $id, false);

        Assert::assertSame([ScimChange::Reactivated], $reactivated->changes);
        Assert::assertSame([ScimChange::Deactivated], $second->changes);
        Assert::assertTrue($second->user->version->equals(new ResourceVersion(4)));
        Assert::assertNotNull($first->key);
        Assert::assertNotNull($second->key);
        Assert::assertFalse($first->key->equals($second->key), 'A deactivation after a reactivation meets another version, so it has another key.');
    }

    #[Test]
    public function a_replace_changes_the_attributes_and_active_at_once(): void
    {
        $scim = $this->scim()->provisioning();
        $id = $scim->createUser($this->acme(), $this->ada())->user->id;
        $renamed = $this->ada(displayName: 'Ada King', active: false);

        $replaced = $scim->replaceUser($this->acme(), $id, $renamed, ResourceVersion::first());

        Assert::assertSame([ScimChange::Replaced, ScimChange::Deactivated], $replaced->changes);
        Assert::assertTrue($replaced->user->user->equals($renamed));
        Assert::assertTrue($replaced->user->version->equals(new ResourceVersion(2)));
        Assert::assertTrue($scim->user($this->acme(), $id)->user->equals($renamed));
    }

    #[Test]
    public function a_replace_never_changes_the_external_id(): void
    {
        $scim = $this->scim()->provisioning();
        $id = $scim->createUser($this->acme(), $this->ada())->user->id;

        $this->expectRefusal(ScimErrorCode::Mutability, fn () => $scim->replaceUser($this->acme(), $id, $this->user('00u-other', 'ada@example.org')));

        $group = $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('Editors'), new Subject('grp-1')))->group;

        $this->expectRefusal(ScimErrorCode::Mutability, fn () => $scim->replaceGroup($this->acme(), $group->id, new ScimGroup(new DisplayName('Editors'), new Subject('grp-2'))));
        $this->expectRefusal(ScimErrorCode::Mutability, fn () => $scim->replaceGroup($this->acme(), $group->id, new ScimGroup(new DisplayName('Editors'))));
    }

    #[Test]
    public function if_match_must_name_the_current_version(): void
    {
        $scim = $this->scim()->provisioning();
        $id = $scim->createUser($this->acme(), $this->ada())->user->id;
        $group = $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('Editors')))->group->id;
        $stale = new ResourceVersion(2);

        $this->expectRefusal(ScimErrorCode::VersionMismatch, fn () => $scim->replaceUser($this->acme(), $id, $this->ada(displayName: 'Ada King'), $stale));
        $this->expectRefusal(ScimErrorCode::VersionMismatch, fn () => $scim->setUserActive($this->acme(), $id, false, $stale));
        $this->expectRefusal(ScimErrorCode::VersionMismatch, fn () => $scim->deleteUser($this->acme(), $id, $stale));
        $this->expectRefusal(ScimErrorCode::VersionMismatch, fn () => $scim->replaceGroup($this->acme(), $group, new ScimGroup(new DisplayName('Authors')), $stale));
        $this->expectRefusal(ScimErrorCode::VersionMismatch, fn () => $scim->changeMembers($this->acme(), $group, new MembershipChange(add: [$id]), $stale));
        $this->expectRefusal(ScimErrorCode::VersionMismatch, fn () => $scim->deleteGroup($this->acme(), $group, $stale));

        Assert::assertTrue($scim->setUserActive($this->acme(), $id, false, ResourceVersion::first())->changed());
    }

    #[Test]
    public function only_the_connection_that_deactivated_a_user_reactivates_it(): void
    {
        $harness = $this->scim();
        $scim = $harness->provisioning();
        $id = $scim->createUser($this->acme(), $this->ada())->user->id;

        $harness->deactivateElsewhere($this->acme(), $id);

        Assert::assertFalse($scim->user($this->acme(), $id)->user->active);
        Assert::assertFalse($scim->setUserActive($this->acme(), $id, false)->changed());
        $this->expectRefusal(ScimErrorCode::ReactivationRefused, fn () => $scim->setUserActive($this->acme(), $id, true));
        $this->expectRefusal(ScimErrorCode::ReactivationRefused, fn () => $scim->replaceUser($this->acme(), $id, $this->ada()));
        Assert::assertFalse($scim->user($this->acme(), $id)->user->active);
    }

    #[Test]
    public function a_deleted_user_answers_not_found_and_leaves_its_groups(): void
    {
        $scim = $this->scim()->provisioning();
        $ada = $scim->createUser($this->acme(), $this->ada())->user->id;
        $alan = $scim->createUser($this->acme(), $this->user('00u-alan', 'alan@example.org'))->user->id;
        $group = $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('Editors'), null, [$ada, $alan]))->group->id;

        $deleted = $scim->deleteUser($this->acme(), $ada);

        Assert::assertSame(ScimResourceType::User, $deleted->type);
        Assert::assertTrue($deleted->id->equals($ada));
        Assert::assertTrue($deleted->key->equals(new ScimIdempotencyKey($this->acme(), ScimResourceType::User, $ada, ScimIdempotencyKey::DELETED, ResourceVersion::first())));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->user($this->acme(), $ada));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->deleteUser($this->acme(), $ada));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->setUserActive($this->acme(), $ada, true));

        $members = $scim->group($this->acme(), $group)->group->members;

        Assert::assertCount(1, $members);
        Assert::assertTrue($members[0]->equals($alan));
    }

    #[Test]
    public function a_group_changes_its_members_once_per_desired_state(): void
    {
        $scim = $this->scim()->provisioning();
        $ada = $scim->createUser($this->acme(), $this->ada())->user->id;
        $alan = $scim->createUser($this->acme(), $this->user('00u-alan', 'alan@example.org'))->user->id;
        $created = $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('Editors'), null, [$ada]));
        $id = $created->group->id;

        $added = $scim->changeMembers($this->acme(), $id, new MembershipChange(add: [$alan]));
        $again = $scim->changeMembers($this->acme(), $id, new MembershipChange(add: [$alan]));
        $removed = $scim->changeMembers($this->acme(), $id, new MembershipChange(remove: [$ada]));
        $renamed = $scim->replaceGroup($this->acme(), $id, new ScimGroup(new DisplayName('Authors'), null, [$alan]));

        Assert::assertSame([ScimChange::Created], $created->changes);
        Assert::assertSame([ScimChange::MembersChanged], $added->changes);
        Assert::assertCount(2, $added->group->group->members);
        Assert::assertFalse($again->changed());
        Assert::assertTrue($again->group->version->equals(new ResourceVersion(2)));
        Assert::assertSame([ScimChange::MembersChanged], $removed->changes);
        Assert::assertSame([ScimChange::Replaced], $renamed->changes);
        Assert::assertTrue($renamed->group->version->equals(new ResourceVersion(4)));
        Assert::assertTrue($scim->group($this->acme(), $id)->group->equals(new ScimGroup(new DisplayName('Authors'), null, [$alan])));
    }

    #[Test]
    public function a_group_is_unique_in_its_connection_by_display_name(): void
    {
        $scim = $this->scim()->provisioning();
        $first = $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('Editors')));

        $again = $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('Editors')));

        Assert::assertFalse($again->changed());
        Assert::assertTrue($again->group->id->equals($first->group->id));
        $this->expectRefusal(ScimErrorCode::Uniqueness, fn () => $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('EDITORS'), new Subject('grp-1'))));
        Assert::assertTrue($scim->createGroup($this->globex(), new ScimGroup(new DisplayName('Editors')))->changed());
    }

    #[Test]
    public function a_deleted_group_answers_not_found(): void
    {
        $scim = $this->scim()->provisioning();
        $id = $scim->createGroup($this->acme(), new ScimGroup(new DisplayName('Editors')))->group->id;

        $deleted = $scim->deleteGroup($this->acme(), $id);

        Assert::assertSame(ScimResourceType::Group, $deleted->type);
        Assert::assertTrue($deleted->key->equals(new ScimIdempotencyKey($this->acme(), ScimResourceType::Group, $id, ScimIdempotencyKey::DELETED, ResourceVersion::first())));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->group($this->acme(), $id));
        $this->expectRefusal(ScimErrorCode::ResourceNotFound, fn () => $scim->deleteGroup($this->acme(), $id));
    }

    private function acme(): ConnectionId
    {
        return new ConnectionId('entra-acme');
    }

    private function globex(): ConnectionId
    {
        return new ConnectionId('okta-globex');
    }

    private function ada(string $displayName = 'Ada Lovelace', bool $active = true): ScimUser
    {
        return new ScimUser(new Subject('00u-ada'), new ScimUserName('ada@example.org'), new DisplayName($displayName), new EmailAddress('ada@example.org'), $active);
    }

    private function user(string $externalId, string $userName): ScimUser
    {
        return new ScimUser(new Subject($externalId), new ScimUserName($userName), null, null);
    }

    /**
     * @param  Closure(): object  $call
     */
    private function expectRefusal(ScimErrorCode $reason, Closure $call): void
    {
        try {
            $call();
        } catch (ScimRefused $refused) {
            Assert::assertSame($reason, $refused->reason);

            return;
        }

        Assert::fail(sprintf('The SCIM call was taken; it must be refused with %s.', $reason->value));
    }
}
