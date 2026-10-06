<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Access\Adapter\PostgresOwnHeldGrants;
use Cbox\Cms\Core\Tests\Access\OwnHeldGrantsBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Closure;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * PostgresOwnHeldGrants against OwnHeldGrantsBehaviour, as the app role in a transaction with the
 * reader's context over the ListingWorld, written as the superuser; and without a context, where
 * the app role has no actor and reads nothing.
 */
final class PostgresOwnHeldGrantsBehaviourTest extends TestCase
{
    use ListingReads;
    use OwnHeldGrantsBehaviour;
    use RealPostgres;

    #[Override]
    protected function heldAs(string $reader, Closure $read): void
    {
        $this->readAs($reader, static fn () => $read(self::grants()));
    }

    #[Test]
    public function it_reads_nothing_without_an_actor_context(): void
    {
        $connection = app(ConnectionResolverInterface::class)->connection();
        $connection->beginTransaction();

        try {
            self::assertSame([], self::grants()->held());
        } finally {
            $connection->rollBack();
        }
    }

    private static function grants(): PostgresOwnHeldGrants
    {
        return new PostgresOwnHeldGrants(app(ConnectionResolverInterface::class));
    }
}
