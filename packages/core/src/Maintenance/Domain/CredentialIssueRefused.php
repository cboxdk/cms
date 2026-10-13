<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use RuntimeException;

/**
 * cms:credential:issue issued nothing, with the catalog error of why (PRD 5.16): the actor does
 * not exist or is not a service actor (validation_failed at actor), it is not active
 * (actor_not_active), the expiry is not after now (validation_failed at expires), or the process
 * cannot write the credential store, because it has no owner connection or the connection named is
 * not the owner role's (maintenance_process_required).
 */
#[Internal]
final class CredentialIssueRefused extends RuntimeException
{
    private function __construct(public readonly CatalogError $error)
    {
        parent::__construct(sprintf('[%s] %s', $error->code->value, $error->message));
    }

    public static function notServiceActor(ActorId $actor, ?Actor $found): self
    {
        return new self(new CatalogError(ErrorCode::ValidationFailed, new FieldPath('actor'), sprintf(
            'A service credential belongs to an active service actor (PRD 5.16), and %s is %s. Nothing was issued.',
            $actor->toString(),
            $found instanceof Actor ? sprintf('a %s actor', $found->class->value) : 'no actor',
        )));
    }

    public static function notActive(Actor $actor): self
    {
        return new self(new CatalogError(ErrorCode::ActorNotActive, new FieldPath('actor'), sprintf(
            'The service actor %s is %s, and a credential is issued only to an active actor (PRD 5.16). Nothing was issued.',
            $actor->id->toString(),
            $actor->state->value,
        )));
    }

    public static function expiry(): self
    {
        return new self(new CatalogError(ErrorCode::ValidationFailed, new FieldPath('expires'), 'A credential expires after it is issued, and the expiry given is not after now. Nothing was issued.'));
    }

    public static function ownerConnectionMissing(): self
    {
        return new self(new CatalogError(ErrorCode::MaintenanceProcessRequired, null, 'A service credential is written as the owner role, and this process has no owner connection. Run cms:credential:issue in the maintenance process, with cbox-cms.database.owner_connection naming the owner role\'s connection. Nothing was issued.'));
    }

    public static function notOwner(string $connection): self
    {
        return new self(new CatalogError(ErrorCode::MaintenanceProcessRequired, null, sprintf(
            'The connection %s may not write a service credential, so it is not the owner role\'s. Point cbox-cms.database.owner_connection at the owner role\'s connection and run the migrations as that role. Nothing was issued.',
            $connection,
        )));
    }
}
