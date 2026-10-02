<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * A SCIM provisioning refused a call, for $reason. Nothing was committed. The message never holds a
 * resource's attributes, so it can be logged.
 */
#[Experimental]
final class ScimRefused extends RuntimeException
{
    private function __construct(public readonly ScimErrorCode $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(ScimErrorCode $reason): self
    {
        return new self($reason, sprintf('The SCIM call was refused: %s.', match ($reason) {
            ScimErrorCode::ResourceNotFound => 'the connection has no resource with the id',
            ScimErrorCode::Uniqueness => 'another resource of the connection has the same externalId, userName or displayName',
            ScimErrorCode::VersionMismatch => 'If-Match names another version than the resource\'s current one',
            ScimErrorCode::Mutability => 'the externalId of a resource never changes',
            ScimErrorCode::InvalidValue => 'a member is not a user of the connection',
            ScimErrorCode::ReactivationRefused => 'only the source that deactivated the actor may reactivate it',
        }));
    }
}
