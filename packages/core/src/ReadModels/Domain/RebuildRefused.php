<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeName;
use RuntimeException;

/**
 * Why a rebuild of a type's read model did not run, or stopped (PRD 4.1, 11.6, invariant 22), with
 * the catalog code in $errorCode:
 *
 * - rebuild_type_unknown: the installation has no type with the name.
 * - rebuild_identity_invalid: cbox-cms.rebuild.service_actor names no service actor that exists.
 * - actor_not_active: the service actor is not active (PRD 5.16).
 * - rebuild_schema_version_unsupported: a revision or head snapshot was written under another
 *   schema version than the type's current one. Upcasters come with B3; until then a rebuild
 *   reads payloads at the current version only, and the chunk that found one rolls back.
 */
#[Experimental]
final class RebuildRefused extends RuntimeException
{
    public const string CODE_TYPE_UNKNOWN = 'rebuild_type_unknown';

    public const string CODE_IDENTITY = 'rebuild_identity_invalid';

    public const string CODE_NOT_ACTIVE = 'actor_not_active';

    public const string CODE_SCHEMA_VERSION = 'rebuild_schema_version_unsupported';

    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function unknownType(TypeName $type): self
    {
        return new self(sprintf('No type of this installation is named %s, so nothing was rebuilt.', $type->value), self::CODE_TYPE_UNKNOWN);
    }

    public static function notConfigured(): self
    {
        return new self('A rebuild runs as a service actor, and cbox-cms.rebuild.service_actor names none. Create a service actor, grant it a role on the nodes whose entries it rebuilds, and name its id there.', self::CODE_IDENTITY);
    }

    public static function unknownActor(ActorId $actor): self
    {
        return new self(sprintf('The service actor %s that cbox-cms.rebuild.service_actor names does not exist.', $actor->toString()), self::CODE_IDENTITY);
    }

    public static function notAService(ActorId $actor, ActorClass $class): self
    {
        return new self(sprintf('The actor %s that cbox-cms.rebuild.service_actor names is a %s actor; a rebuild runs only as a service actor.', $actor->toString(), $class->value), self::CODE_IDENTITY);
    }

    public static function notActive(ActorId $actor, ActorState $state): self
    {
        return new self(sprintf('The service actor %s is %s, not active, so it rebuilds nothing (PRD 5.16).', $actor->toString(), $state->value), self::CODE_NOT_ACTIVE);
    }

    public static function schemaVersion(TypeName $type, EntryId $entry, VariantKey $variant, int $found, int $current): self
    {
        return new self(sprintf(
            'The variant %s of the entry %s of %s holds a payload at schema version %d, and the type is at version %d. A rebuild reads payloads at the type\'s current version only, until the upcasters come; the chunk rolled back.',
            $variant->value,
            $entry->toString(),
            $type->value,
            $found,
            $current,
        ), self::CODE_SCHEMA_VERSION);
    }
}
