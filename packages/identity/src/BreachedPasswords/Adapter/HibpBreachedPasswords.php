<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\BreachedPasswords\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Egress\EgressFailed;
use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\EgressHeader;
use Cbox\Cms\Contracts\Egress\EgressRequest;
use Cbox\Cms\Contracts\Egress\HostClass;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Override;
use SensitiveParameter;

/**
 * BreachedPasswords on the range API of Have I Been Pwned's Pwned Passwords, with k-anonymity: the
 * password's SHA-1 is computed here, and only its first five hex digits leave the process, in the
 * path of GET https://api.pwnedpasswords.com/range/{prefix}, through the egress gateway. The
 * service answers with the remaining 35 hex digits of every hash it knows with that prefix and how
 * often each was seen, and the match is made here. The header Add-Padding: true makes it pad the
 * answer with entries seen 0 times, so the size of the answer does not tell the prefix either; a
 * padding entry never counts as a match.
 *
 * It fails closed: an EgressFailed, a status other than 200 or an answer that is not lines of 35
 * hex digits, a colon and a count throw BreachedPasswordsUnavailable (retryable). Every check adds 1
 * to the counter cms.identity.breached_passwords.checks under cms.outcome: breached, clean or
 * unavailable.
 */
#[Internal]
final readonly class HibpBreachedPasswords implements BreachedPasswords
{
    public const string RANGE_URL = 'https://api.pwnedpasswords.com/range/';

    public const string HOST_CLASS = 'breached_passwords';

    /** How many hex digits of the SHA-1 leave the process. */
    public const int PREFIX_LENGTH = 5;

    public const string CHECKS = 'cms.identity.breached_passwords.checks';

    public const string OUTCOME = 'cms.outcome';

    /** One line of the answer: the rest of a hash and how often it was seen. */
    private const string LINE = '/\A([0-9A-F]{35}):([0-9]{1,10})\z/';

    public function __construct(private EgressGateway $egress, private Telemetry $telemetry) {}

    #[Override]
    public function isBreached(Password $password): bool
    {
        try {
            $breached = $this->check(strtoupper(sha1($password->reveal())));
        } catch (BreachedPasswordsUnavailable $unavailable) {
            $this->count('unavailable');

            throw $unavailable;
        }

        $this->count($breached ? 'breached' : 'clean');

        return $breached;
    }

    /**
     * @throws BreachedPasswordsUnavailable
     */
    private function check(#[SensitiveParameter] string $hash): bool
    {
        $prefix = substr($hash, 0, self::PREFIX_LENGTH);
        $suffix = substr($hash, self::PREFIX_LENGTH);

        try {
            $response = $this->egress->get(new EgressRequest(
                new HostClass(self::HOST_CLASS),
                self::RANGE_URL.$prefix,
                [new EgressHeader('Add-Padding', 'true')],
            ));
        } catch (EgressFailed $failed) {
            throw BreachedPasswordsUnavailable::because(sprintf('the range API did not answer (%s).', $failed->errorCode));
        }

        if ($response->status !== 200) {
            throw BreachedPasswordsUnavailable::because(sprintf('the range API answered with the status %d.', $response->status));
        }

        $breached = false;

        foreach (preg_split('/\r?\n/', trim($response->body)) ?: [] as $line) {
            if (preg_match(self::LINE, $line, $entry) !== 1) {
                throw BreachedPasswordsUnavailable::because('the range API answered with a line that is not a hash suffix and a count.');
            }

            // Every line is compared, in constant time, so the time taken does not depend on where
            // the password's suffix is.
            if (hash_equals($entry[1], $suffix) && ltrim($entry[2], '0') !== '') {
                $breached = true;
            }
        }

        return $breached;
    }

    private function count(string $outcome): void
    {
        $this->telemetry->counter(new CounterRecord(
            new TelemetryName(self::CHECKS),
            1,
            new Attributes(Attribute::of(self::OUTCOME, $outcome)),
        ));
    }
}
