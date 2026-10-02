<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;

/**
 * SCIM 2.0 provisioning (RFC 7643, RFC 7644) of the users and groups of one connection (PRD 5.16,
 * "Signaler fra IdP'en"). The SCIM server verifies the connection's token, which names the
 * connection, and calls these methods with it. Each connection has its own token, which gives full
 * control of the connection's users and groups and of nothing else: a resource of another
 * connection, or a local account, is scim_resource_not_found, and a member that is not a user of the
 * connection is scim_invalid_value. Issuing, showing, hashing and rotating the tokens is not part of
 * this contract.
 *
 * Each change is committed in the CMS before the call returns, through the command its ScimChange
 * names, with the outcome's ScimIdempotencyKey as the command's unit of work; when a command fails,
 * the call throws and the identity provider retries. A call that asks for the state the resource has
 * changes nothing, so a repeated request has one effect. A user's externalId is the immutable value
 * of the connection's declared claim, and an email address is never a key. Users are unique within
 * the connection by externalId and by userName, groups by displayName, both without regard to case
 * where RFC 7643 says so. If-Match, when given, must name the current version.
 *
 * The identity module's implementation, the SCIM server, comes with B6.
 */
#[Experimental]
interface ScimProvisioning
{
    /**
     * POST /Users. A user with the same externalId and the same state is the first request again and
     * changes nothing.
     *
     * @throws ScimRefused
     */
    public function createUser(ConnectionId $connection, ScimUser $user): ScimUserOutcome;

    /**
     * PUT /Users/{id}: the user's whole state, active included.
     *
     * @throws ScimRefused
     */
    public function replaceUser(ConnectionId $connection, ScimResourceId $id, ScimUser $user, ?ResourceVersion $ifMatch = null): ScimUserOutcome;

    /**
     * PATCH /Users/{id} of active alone. active=true reactivates only an actor this connection
     * deactivated; any other is scim_reactivation_refused and stays deactivated.
     *
     * @throws ScimRefused
     */
    public function setUserActive(ConnectionId $connection, ScimResourceId $id, bool $active, ?ResourceVersion $ifMatch = null): ScimUserOutcome;

    /**
     * DELETE /Users/{id}: actor.deprovision. The user leaves every group of the connection.
     *
     * @throws ScimRefused
     */
    public function deleteUser(ConnectionId $connection, ScimResourceId $id, ?ResourceVersion $ifMatch = null): ScimDeletion;

    /**
     * GET /Users/{id}.
     *
     * @throws ScimRefused
     */
    public function user(ConnectionId $connection, ScimResourceId $id): ScimUserResource;

    /**
     * POST /Groups. A group with the same displayName and the same state is the first request again
     * and changes nothing.
     *
     * @throws ScimRefused
     */
    public function createGroup(ConnectionId $connection, ScimGroup $group): ScimGroupOutcome;

    /**
     * PUT /Groups/{id}: the group's whole state, members included.
     *
     * @throws ScimRefused
     */
    public function replaceGroup(ConnectionId $connection, ScimResourceId $id, ScimGroup $group, ?ResourceVersion $ifMatch = null): ScimGroupOutcome;

    /**
     * PATCH /Groups/{id} of members.
     *
     * @throws ScimRefused
     */
    public function changeMembers(ConnectionId $connection, ScimResourceId $id, MembershipChange $change, ?ResourceVersion $ifMatch = null): ScimGroupOutcome;

    /**
     * DELETE /Groups/{id}. Its memberships end.
     *
     * @throws ScimRefused
     */
    public function deleteGroup(ConnectionId $connection, ScimResourceId $id, ?ResourceVersion $ifMatch = null): ScimDeletion;

    /**
     * GET /Groups/{id}.
     *
     * @throws ScimRefused
     */
    public function group(ConnectionId $connection, ScimResourceId $id): ScimGroupResource;
}
