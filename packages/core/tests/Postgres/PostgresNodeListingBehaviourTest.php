<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Structure\Adapter\PostgresNodeListing;
use Cbox\Cms\Core\Tests\Structure\NodeListingBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * PostgresNodeListing against NodeListingBehaviour, as the app role in a transaction with the
 * reader's context over the ListingWorld, written as the superuser.
 */
final class PostgresNodeListingBehaviourTest extends TestCase
{
    use ListingReads;
    use NodeListingBehaviour;
    use RealPostgres;

    #[Override]
    protected function listAs(string $reader, Closure $read): void
    {
        $this->readAs($reader, static fn () => $read(new PostgresNodeListing(app(ConnectionResolverInterface::class))));
    }
}
