<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;

/**
 * The idempotency key of a SCIM change (PRD 5.16): the connection, the resource, the desired state
 * and the resource's version in the CMS before the change, null for a create. A SCIM request has no
 * id, so the version is what tells a repeated request, which gives the same key and so the same
 * command, from a new one: a deactivation after a reactivation meets another version and is a new
 * change. unitOfWork() is the unit of work of the command's internal envelope, so the pipeline
 * replays a command whose key it has committed.
 */
#[Experimental]
final readonly class ScimIdempotencyKey
{
    /** The desired state of a DELETE. */
    public const string DELETED = 'deleted';

    public ContentHash $desired;

    /**
     * @param  string  $desired  the canonical text of the desired state: ScimUser::canonical() or ScimGroup::canonical() of the state the call asks for, or DELETED
     */
    public function __construct(
        public ConnectionId $connection,
        public ScimResourceType $type,
        public ScimResourceId $resource,
        string $desired,
        public ?ResourceVersion $version,
    ) {
        $this->desired = ContentHash::of($desired);
    }

    /**
     * Joins texts so that no two lists give the same result: each part is prefixed with its length
     * in bytes.
     */
    public static function canonical(string ...$parts): string
    {
        return implode('', array_map(static fn (string $part): string => strlen($part).':'.$part, $parts));
    }

    public function unitOfWork(): UnitOfWork
    {
        return new UnitOfWork('scim:'.hash('sha256', self::canonical(
            $this->connection->value,
            $this->type->value,
            $this->resource->value,
            $this->desired->value,
            $this->version instanceof ResourceVersion ? (string) $this->version->value : '0',
        )));
    }

    public function equals(self $other): bool
    {
        return $this->unitOfWork()->equals($other->unitOfWork());
    }
}
