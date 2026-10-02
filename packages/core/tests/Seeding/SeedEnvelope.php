<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The envelope a seed chunk's call carries, as SeedChunks builds it, for the tests that ask the
 * seeder's authorizer directly.
 */
final class SeedEnvelope
{
    public const string ACTOR = '0192a0c0-0000-7000-8000-0000000047c1';

    public static function of(): Envelope
    {
        return Envelope::internal(IssuingSurface::Seed, IssuerKind::Seed, ActorId::fromString(self::ACTOR), new UnitOfWork('seed:test'), new CorrelationId('seed-test'));
    }
}
