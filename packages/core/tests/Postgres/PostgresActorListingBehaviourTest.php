<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Identity\Adapter\PostgresActorListing;
use Cbox\Cms\Core\Tests\Identity\ActorListingBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * PostgresActorListing against ActorListingBehaviour, as the app role in a transaction with the
 * reader's context over the ListingWorld, written as the superuser.
 */
final class PostgresActorListingBehaviourTest extends TestCase
{
    use ActorListingBehaviour;
    use ListingReads;
    use RealPostgres;

    #[Override]
    protected function listAs(string $reader, Closure $read): void
    {
        $this->readAs($reader, static fn () => $read(new PostgresActorListing(app(ConnectionResolverInterface::class))));
    }
}
