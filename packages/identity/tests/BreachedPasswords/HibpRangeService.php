<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\BreachedPasswords;

use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressHeader;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressRequest;
use Cbox\Cms\Core\Egress\Domain\Dto\EgressResponse;
use Cbox\Cms\Core\Tests\Egress\Fakes\FakeEgressGateway;
use Cbox\Cms\Identity\BreachedPasswords\Adapter\HibpBreachedPasswords;
use Cbox\Cms\Testkit\Identity\BreachedPasswordsHarness;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Override;

/**
 * The Pwned Passwords range API as a faked transport behind the fake egress gateway, and the
 * harness of the shared suite for HibpBreachedPasswords. Like the service, it answers
 * GET https://api.pwnedpasswords.com/range/{5 hex digits} with a line SUFFIX:COUNT for every
 * breached hash with that prefix, CRLF between lines, and with Add-Padding: true it pads the
 * answer with PADDING lines seen 0 times, among them the suffix of an unbreached password, so a
 * padding line that matches never counts. Any other path is a 404.
 */
final class HibpRangeService implements BreachedPasswordsHarness
{
    /** The password whose suffix pads every answer with a count of 0. */
    public const string PADDED_PASSWORD = 'correct horse battery staple 41';

    public const int PADDING = 12;

    public readonly FakeEgressGateway $egress;

    public readonly FakeTelemetry $telemetry;

    /** @var array<string, int> */
    private array $breached = [];

    public function __construct()
    {
        $this->telemetry = new FakeTelemetry;
        $this->egress = new FakeEgressGateway($this->telemetry);
        $this->egress->answerOthers(fn (EgressRequest $request): EgressResponse => $this->range($request));
    }

    #[Override]
    public function breachedPasswords(): BreachedPasswords
    {
        return new HibpBreachedPasswords($this->egress, $this->telemetry);
    }

    #[Override]
    public function breach(Password $password, int $seen = 3): void
    {
        $this->breached[strtoupper(sha1($password->reveal()))] = $seen;
    }

    #[Override]
    public function goDown(): void
    {
        $this->egress->goDown();
    }

    private function range(EgressRequest $request): EgressResponse
    {
        if (preg_match('#\A'.preg_quote(HibpBreachedPasswords::RANGE_URL, '#').'([0-9A-F]{5})\z#', $request->url, $match) !== 1) {
            return new EgressResponse(404, 'Not found');
        }

        $lines = [];

        foreach ($this->breached as $hash => $seen) {
            if (str_starts_with($hash, $match[1])) {
                $lines[substr($hash, 5)] = sprintf('%s:%d', substr($hash, 5), $seen);
            }
        }

        if ($this->padded($request)) {
            $padded = substr(strtoupper(sha1(self::PADDED_PASSWORD)), 5);
            $lines[$padded] ??= $padded.':0';

            for ($line = 1; $line < self::PADDING; $line++) {
                $suffix = substr(strtoupper(sha1($match[1].'padding'.$line)), 5);
                $lines[$suffix] ??= $suffix.':0';
            }
        }

        ksort($lines);

        return new EgressResponse(200, implode("\r\n", $lines));
    }

    private function padded(EgressRequest $request): bool
    {
        return array_any($request->headers, fn (EgressHeader $header): bool => strcasecmp($header->name, 'Add-Padding') === 0 && $header->value === 'true');
    }
}
