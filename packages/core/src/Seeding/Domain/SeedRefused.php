<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use RuntimeException;

/**
 * Why a seed run cannot seed: its service actor, what the actor reaches, what the catalog has, or a
 * chunk the kernel rejected. It carries the exit code a console command ends with.
 */
#[Internal]
final class SeedRefused extends RuntimeException
{
    private function __construct(string $message, public readonly ExitCode $exit)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('The seeder writes as a service actor, and cbox-cms.seeding.service_actor names none. Create a service actor with a grant on the nodes to seed below and name its id there.', ExitCode::Config);
    }

    public static function unknown(ActorId $actor): self
    {
        return new self(sprintf('The service actor %s that cbox-cms.seeding.service_actor names does not exist.', $actor->toString()), ExitCode::Config);
    }

    public static function notAService(ActorId $actor, ActorClass $class): self
    {
        return new self(sprintf('The actor %s that cbox-cms.seeding.service_actor names is a %s actor; the seeder writes only as a service actor.', $actor->toString(), $class->value), ExitCode::Config);
    }

    public static function notActive(ActorId $actor, ActorState $state): self
    {
        return new self(sprintf('The service actor %s is %s, not active, so it cannot seed (PRD 5.16).', $actor->toString(), $state->value), ExitCode::NoPerm);
    }

    public static function noNodes(ActorId $actor): self
    {
        return new self(sprintf('The service actor %s reaches no node that can be the home of an entry, so there is nowhere to seed. Grant it a role on the nodes to seed below.', $actor->toString()), ExitCode::NoPerm);
    }

    /**
     * @param  list<string>  $reasons  why each type of the catalog cannot be seeded
     */
    public static function noTypes(array $reasons): self
    {
        return new self(sprintf(
            'No type of the catalog can be seeded%s',
            $reasons === [] ? ': the catalog has no types.' : ': '.implode(' ', $reasons),
        ), ExitCode::Config);
    }

    public static function rejected(string $unit, CatalogError $error, CatalogError ...$more): self
    {
        $lines = array_map(
            static fn (CatalogError $each): string => sprintf('%s%s: %s', $each->code->value, $each->path instanceof FieldPath ? ' at '.$each->path->toString() : '', $each->message),
            [$error, ...$more],
        );

        return new self(sprintf('The kernel rejected the seed chunk %s: %s', $unit, implode(' ', $lines)), ExitCode::Software);
    }
}
