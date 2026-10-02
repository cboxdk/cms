<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\LocalAccounts;

use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\LoginConnection;
use Cbox\Cms\Contracts\Identity\Login\LoginResponse;
use Cbox\Cms\Contracts\Identity\Login\LoginStarted;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use Cbox\Cms\Contracts\Identity\Login\SubmittedCredentials;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Identity\LocalAccounts\Domain\LocalConnection;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Login\AcceptedLogin;
use Cbox\Cms\Testkit\Login\LoginConnectionHarness;
use Override;

/**
 * The harness of LoginConnectionContract for the local connection: one local account, bound in the
 * store a test gives, whose email and password the login form takes. The provider's side is the
 * store and the hasher: an accepted login is the account's credentials, a refused one its email
 * with a wrong password. The assertion's subject is the actor's id and its amr `pwd`.
 */
final readonly class LocalLogins implements LoginConnectionHarness
{
    public const string EMAIL = 'ada.lovelace@example.org';

    public const string PASSWORD = 'correct horse battery staple';

    private LocalConnection $connection;

    public function __construct(
        LocalCredentialStore $store,
        private ActorId $actor,
        private Issuer $issuer = new Issuer('https://cms.example.org'),
        FakeClock $clock = new FakeClock,
    ) {
        $hasher = new CountingPasswordHasher;
        $store->bind($actor, new LoginIdentifier(self::EMAIL), $hasher->hash(new Password(self::PASSWORD)));
        $this->connection = new LocalConnection($store, $hasher, $issuer, $clock);
    }

    #[Override]
    public function connection(): LoginConnection
    {
        return $this->connection;
    }

    #[Override]
    public function issuer(): Issuer
    {
        return $this->issuer;
    }

    #[Override]
    public function accepted(LoginStarted $started): AcceptedLogin
    {
        return new AcceptedLogin(
            new SubmittedCredentials($started->pending->state->value, self::EMAIL, self::PASSWORD),
            new Subject($this->actor->toString()),
            [new AuthenticationMethod(LocalConnection::PASSWORD_METHOD)],
        );
    }

    #[Override]
    public function refused(LoginStarted $started): LoginResponse
    {
        return new SubmittedCredentials($started->pending->state->value, self::EMAIL, self::PASSWORD.' but wrong');
    }
}
