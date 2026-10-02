<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\OperatorProbe;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Maintenance\Adapter\PostgresInstallationOperator;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Throwable;

/**
 * Reads the installation operator on the doctor's own connection, a copy of the app role's with a
 * connect timeout, through the kernel's lookups: `cms_installation_operator()` and then the actor
 * directory's `cms_identity_actor()`.
 */
#[Internal]
final readonly class ConnectionOperatorProbe implements OperatorProbe
{
    public function __construct(
        private DoctorConnection $connection,
        private ConnectionResolverInterface $connections,
    ) {}

    #[Override]
    public function operator(): ?Actor
    {
        $name = $this->connection->get()->getName();

        try {
            $id = new PostgresInstallationOperator($this->connections, $name)->find();
            $actor = $id instanceof ActorId ? new PostgresActorDirectory($this->connections, $name)->find($id) : null;
        } catch (Throwable $thrown) {
            throw PostgresErrors::classify($thrown);
        }

        if ($id instanceof ActorId && ! $actor instanceof Actor) {
            throw ProbeFailed::violation(sprintf('The installation names the operator %s, and no actor has that id.', $id->toString()));
        }

        return $actor;
    }
}
