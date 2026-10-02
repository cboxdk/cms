<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Access\Adapter\PostgresAccessListings;
use Cbox\Cms\Core\Tests\Access\AccessListingsBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * PostgresAccessListings against AccessListingsBehaviour, as the app role in a transaction with the
 * reader's context over the ListingWorld, written as the superuser.
 */
final class PostgresAccessListingsBehaviourTest extends TestCase
{
    use AccessListingsBehaviour;
    use ListingReads;
    use RealPostgres;

    #[Override]
    protected function listAs(string $reader, Closure $read): void
    {
        $this->readAs($reader, static fn () => $read(new PostgresAccessListings(app(ConnectionResolverInterface::class))));
    }
}
