<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Identity\LoginPolicy\Adapter\PostgresIdpLinks;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use Cbox\Cms\Identity\Tests\LoginPolicy\IdpLinksBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * IdpLinksBehaviour against PostgresIdpLinks on real Postgres: the links are written to
 * cms_identity.idp_links on the identity role's connection, as a federated connection will write
 * them, and the actors they point at by the testkit's seeder as the owner role.
 */
final class PostgresIdpLinksBehaviourTest extends TestCase
{
    use IdpLinksBehaviour;
    use RealPostgres;

    private const string NOW = '2026-03-10T12:00:00Z';

    private ?PostgresIdentitySeeder $seeder = null;

    #[Override]
    protected function links(): IdpLinks
    {
        return app(IdpLinks::class);
    }

    #[Override]
    protected function newActor(): ActorId
    {
        if (! $this->seeder instanceof PostgresIdentitySeeder) {
            $clock = new FakeClock(new DateTimeImmutable(self::NOW));
            $this->seeder = new PostgresIdentitySeeder(app(DatabaseManager::class), $clock, new FakeIdGenerator(clock: $clock));
        }

        return $this->seeder->addActor(ActorClass::Staff, ActorState::Active)->id;
    }

    #[Override]
    protected function link(ActorId $actor, IdpIdentity $identity): void
    {
        DB::connection('pgsql_identity')->table(CredentialStore::table(PostgresIdpLinks::TABLE))->insert([
            'connection' => $identity->connection->value,
            'issuer' => $identity->issuer->value,
            'subject' => $identity->subject->value,
            'actor_id' => $actor->toString(),
            'created_at' => self::NOW,
        ]);
    }

    public function test_the_container_binds_the_links_of_the_identity_connection(): void
    {
        self::assertInstanceOf(PostgresIdpLinks::class, $this->links());
    }

    public function test_the_app_role_cannot_read_the_links(): void
    {
        try {
            DB::connection()->select('select * from cms_identity.idp_links');
            self::fail('Expected the app role to be refused the IdP links.');
        } catch (QueryException $exception) {
            self::assertSame('42501', $exception->getCode());
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function rowsOutOfForm(): iterable
    {
        yield 'a connection with capitals' => [['connection' => 'Google'], 'idp_links_connection'];
        yield 'an issuer that is no URL' => [['issuer' => 'accounts.google.com'], 'idp_links_issuer'];
        yield 'an issuer with a query' => [['issuer' => 'https://accounts.google.com/?a=b'], 'idp_links_issuer'];
        yield 'a subject with a space' => [['subject' => 'a 1'], 'idp_links_subject'];
        yield 'an empty subject' => [['subject' => ''], 'idp_links_subject'];
    }

    /**
     * @param  array<string, string>  $change
     */
    #[DataProvider('rowsOutOfForm')]
    public function test_a_check_refuses_a_link_out_of_form(array $change, string $constraint): void
    {
        $row = [
            'connection' => 'google',
            'issuer' => 'https://accounts.google.com',
            'subject' => 'a-1',
            'actor_id' => $this->newActor()->toString(),
            'created_at' => self::NOW,
        ];

        try {
            DB::connection('pgsql_identity')->table(CredentialStore::table(PostgresIdpLinks::TABLE))->insert([...$row, ...$change]);
            self::fail("Expected {$constraint} to refuse the link.");
        } catch (QueryException $exception) {
            self::assertSame('23514', $exception->getCode());
            self::assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}
