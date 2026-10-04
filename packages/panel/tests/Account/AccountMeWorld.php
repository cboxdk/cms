<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Account;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\Dto\OwnGrant;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Identity\Fakes\FakeOwnActorReader;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

/**
 * The who-am-I page's read over fakes (GUARDRAILS 9, PRD 5.16, 13.4): the real QueryPipeline with
 * the action of actor.me over a FakeOwnActorReader that gives one person their self, the
 * credential verifier of the login world the person signed in through, so the session credential
 * the panel carries verifies, a FakeAccessResolver that gives the person public access over the
 * root, and the query authorizer deciding as the kernel's does, from no grant at all, so the page
 * reads only because actor.me is an ActorQuery. refusing() gives a pipeline whose authorizer
 * refuses every read, for the page's rejected state.
 */
final readonly class AccountMeWorld
{
    public const string ROLE = '0192a0c0-0000-7000-8000-000000000c11';

    public const string GRANT = '0192a0c0-0000-7000-8000-000000000c21';

    public const string NODE = '0192a0c0-0000-7000-8000-000000000c31';

    public const string NAME = 'Jonas Berg';

    public const string ROLE_HANDLE = 'desk';

    public function __construct(
        private CredentialVerifier $verifier,
        public ActorId $person,
        public string $email,
    ) {}

    /**
     * The person's own self as actor.me gives it: an active member of staff with a profile and one
     * grant of the role desk on the node in da.
     */
    public function self(): ActorMe
    {
        return new ActorMe(
            $this->person,
            ActorClass::Staff,
            ActorState::Active,
            AggregateVersion::first(),
            new ActorProfile(new DisplayName(self::NAME), new EmailAddress($this->email)),
            [new OwnGrant(GrantId::fromString(self::GRANT), RoleId::fromString(self::ROLE), new RoleHandle(self::ROLE_HANDLE), NodeId::fromString(self::NODE), GrantEffect::Allow, [new Locale('da')], AggregateVersion::first())],
        );
    }

    /**
     * The pipeline that answers actor.me with the person's self.
     */
    public function pipeline(): QueryPipeline
    {
        return $this->build(FakeQueryAuthorizer::granting(new FakePermissions([])));
    }

    /**
     * The pipeline that refuses every read.
     */
    public function refusing(): QueryPipeline
    {
        return $this->build(new FakeQueryAuthorizer('The test refuses every read.'));
    }

    private function build(FakeQueryAuthorizer $authorizer): QueryPipeline
    {
        $clock = new FakeClock;
        $access = new FakeAccessResolver;
        $access->grant($this->person, [new AccessRegion(new NodePath('a1'))], ClassificationAccess::Public);

        return new QueryPipeline(
            new FakeQueryActions([WhoAmI::class => ProbeQueryBinding::of(new WhoAmIAction(new FakeOwnActorReader($this->person)->add($this->self())), 'actor.me', 1)]),
            $this->verifier,
            $access,
            $authorizer,
            new QuerySettings(new QueryCost(200), new QueryCost(1000)),
            new ReadableFields(new FakeTypeCatalog),
            new FakeReadAudit,
            new FakeQueryTransaction(sessions: $access),
            new PipelineTelemetry(new FakeTelemetry, $clock, new FakeStopwatch),
        );
    }
}
