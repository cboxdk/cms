<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Panel;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Testkit\Panel\Boundary\FillOrderScript;
use Illuminate\Container\Container;
use JsonException;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;
use PHPUnit\Framework\Assert;
use SensitiveParameter;

/**
 * A panel page visited as an actor in a Pest Browser test (PRD 13.4, section 7 of the panel
 * extension architecture): `visitPanelAs`. It gives the actor a local account with a password of
 * the test's own through the LocalCredentialStore, signs in through the panel's login page as a
 * person does, and lands on the path given below the panel's prefix, `/cms` unless the
 * application mounts the panel elsewhere.
 *
 *     $visit = PanelVisit::as($actor->id, '/');
 *     $visit->assertFill('approvals.badge')
 *         ->assertFillOrder('account.me.sections@1', ['cms.profile', 'approvals.badge']);
 *
 * The assertions read the attributes the panel's host puts on every contribution it renders,
 * data-cms-point and data-cms-contribution, so they hold whatever the contribution renders.
 */
#[Experimental]
final readonly class PanelVisit
{
    /** Where the workbench and an application by default mount the panel. */
    public const string DEFAULT_PREFIX = '/cms';

    /**
     * @param  AwaitableWebpage|Webpage  $page  the page the visit landed on, for every assertion the browser plugin has; the plugin gives a page that retries its assertions until its timeout
     */
    private function __construct(
        public AwaitableWebpage|Webpage $page,
        public string $prefix,
    ) {}

    /**
     * Signs in as the actor through the login page and lands on the path below the prefix.
     *
     * @param  string  $path  the path below the panel's prefix, `/` for its start page
     */
    public static function as(ActorId $actor, string $path = '/', string $prefix = self::DEFAULT_PREFIX): self
    {
        $prefix = '/'.trim($prefix, '/');
        $email = sprintf('panel-visit-%s@example.test', bin2hex(random_bytes(6)));
        $password = bin2hex(random_bytes(16));

        self::bindAccount($actor, $email, $password);

        $page = visit($prefix.'/login')
            ->type('email', $email)
            ->type('password', $password)
            ->click('button[type="submit"]')
            ->assertPathIs($prefix);

        $target = rtrim($prefix, '/').'/'.ltrim($path, '/');

        if (rtrim($target, '/') !== $prefix) {
            $page = $page->navigate($target);
        }

        return new self($page, $prefix);
    }

    /**
     * Asserts that the page renders the contribution, on any point.
     */
    public function assertFill(string $contribution): self
    {
        $this->page->assertPresent(sprintf('[data-cms-contribution="%s"]', $contribution));

        return $this;
    }

    /**
     * Asserts that the page renders no contribution with the id.
     */
    public function assertNoFill(string $contribution): self
    {
        $this->page->assertNotPresent(sprintf('[data-cms-contribution="%s"]', $contribution));

        return $this;
    }

    /**
     * Asserts that the point renders exactly the contributions, in this order.
     *
     * @param  list<string>  $contributions
     *
     * @throws JsonException
     */
    public function assertFillOrder(string $point, array $contributions): self
    {
        $rendered = $this->page->script(FillOrderScript::for($point));

        Assert::assertSame($contributions, $rendered, sprintf('The point %s renders other contributions, or in another order.', $point));

        return $this;
    }

    /**
     * Gives the actor a local account with the login and the password, through the installation's
     * credential store, hashed as the identity module hashes a password.
     */
    private static function bindAccount(ActorId $actor, string $email, #[SensitiveParameter] string $password): void
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        Container::getInstance()
            ->make(LocalCredentialStore::class)
            ->bind($actor, new LoginIdentifier($email), new PasswordHash($hash));
    }
}
