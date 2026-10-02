<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why a SCIM call was refused (PRD 5.16, RFC 7644 3.12). Each is a code of the error catalog,
 * Cbox\Cms\Contracts\Errors\ErrorCode; scimType() gives the scimType of the SCIM error response,
 * where RFC 7644 has one.
 */
#[Experimental]
enum ScimErrorCode: string
{
    /** No resource of the connection has the id: it never existed, was deleted, or belongs to another connection. */
    case ResourceNotFound = 'scim_resource_not_found';

    /** Another resource of the connection has the externalId, the userName or the group's displayName. */
    case Uniqueness = 'scim_uniqueness';

    /** If-Match names another version than the resource's current one. */
    case VersionMismatch = 'scim_version_mismatch';

    /** The call would change the externalId of a user or group, which never changes. */
    case Mutability = 'scim_mutability';

    /** A member is not a user of the connection. */
    case InvalidValue = 'scim_invalid_value';

    /** active=true for an actor that another source than the connection deactivated. */
    case ReactivationRefused = 'scim_reactivation_refused';

    /**
     * The scimType of RFC 7644 3.12 for the error, or null where the RFC has none for its status.
     */
    public function scimType(): ?string
    {
        return match ($this) {
            self::Uniqueness => 'uniqueness',
            self::Mutability => 'mutability',
            self::InvalidValue => 'invalidValue',
            self::ResourceNotFound, self::VersionMismatch, self::ReactivationRefused => null,
        };
    }
}
