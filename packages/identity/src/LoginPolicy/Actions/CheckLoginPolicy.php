<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginAttempt;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\IdpLinks;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginDecision;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginPolicyRefused;

/**
 * Asks the login policy about a login (PRD 5.16, "Loginpolitik"). Every login path calls it with
 * the attempt a connection verified, and issues a session only with the LoginDecision it returns.
 *
 * It reads the actor's class, state and credential generation through the ActorDirectory, the
 * state in Postgres, and decides with LoginDecision::decide(), which reads the actor's IdP links
 * for a local login (invariant 38). Every decision adds 1 to the counter `cms.login.decisions`
 * with `cms.outcome` `allowed` or `refused` and, for a refusal, `cms.error.code`, the catalog code
 * of the rule that refused it: never the actor, a login identifier or an e-mail address
 * (GUARDRAILS 5).
 */
#[Internal]
final readonly class CheckLoginPolicy
{
    public const string DECISIONS = 'cms.login.decisions';

    public const string OUTCOME = 'cms.outcome';

    public const string ERROR_CODE = 'cms.error.code';

    public const string ALLOWED = 'allowed';

    public const string REFUSED = 'refused';

    public function __construct(
        private LoginPolicy $policy,
        private ActorDirectory $actors,
        private IdpLinks $links,
        private Telemetry $telemetry,
    ) {}

    /**
     * @throws LoginPolicyRefused
     */
    public function check(LoginAttempt $attempt): LoginDecision
    {
        try {
            $decision = LoginDecision::decide($this->policy, $this->actors->find($attempt->actor), $attempt, $this->links);
        } catch (LoginPolicyRefused $refused) {
            $this->count(Attribute::of(self::OUTCOME, self::REFUSED), Attribute::of(self::ERROR_CODE, $refused->reason->value));

            throw $refused;
        }

        $this->count(Attribute::of(self::OUTCOME, self::ALLOWED));

        return $decision;
    }

    private function count(Attribute ...$attributes): void
    {
        $this->telemetry->counter(new CounterRecord(new TelemetryName(self::DECISIONS), attributes: new Attributes(...$attributes)));
    }
}
