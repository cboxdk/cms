<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Results\CatalogError;
use RuntimeException;

/**
 * A registration of a service actor stopped, with the catalog error of why (PRD 5.16): a command
 * the pipeline rejected, such as installation_operator_missing before cms:install, or
 * validation_failed for a responsible person that is not an active staff actor. $pending is the
 * actor when the registration stopped after actor.register, which left it pending; null when it
 * stopped before anything was written. The message never holds the email address.
 */
#[Internal]
final class ServiceRegistrationRefused extends RuntimeException
{
    private function __construct(public readonly CatalogError $error, public readonly ?ActorId $pending)
    {
        parent::__construct(sprintf('[%s] %s', $error->code->value, $error->message));
    }

    public static function rejected(CatalogError $error, ?ActorId $pending): self
    {
        return new self(new CatalogError($error->code, $error->path, $error->message.($pending instanceof ActorId
            ? sprintf(' Actor %s stays pending; the job that deprovisions actors pending for 24 hours comes with block B6, and until then a deactivation retires it.', $pending->toString())
            : ' Nothing was written.')), $pending);
    }
}
