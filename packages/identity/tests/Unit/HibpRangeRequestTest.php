<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Contracts\Egress\EgressGateway;
use Cbox\Cms\Contracts\Egress\EgressHeader;
use Cbox\Cms\Contracts\Egress\EgressResponse;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Identity\BreachedPasswords\Adapter\HibpBreachedPasswords;
use Cbox\Cms\Identity\Tests\BreachedPasswords\HibpRangeService;
use Cbox\Cms\Testkit\Egress\FakeEgressGateway;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

// What HibpBreachedPasswords sends to the Pwned Passwords range API and how it reads the answer
// (PRD 5.16, k-anonymity): only the first five hex digits of the SHA-1 leave the process, with the
// padding header, and anything but a readable 200 fails closed.

const BREACHED = 'Summer2026!Summer';

/**
 * The outcome attribute of each check counted.
 *
 * @return list<string>
 */
function checkOutcomes(FakeTelemetry $telemetry): array
{
    return array_values(array_map(
        static fn (CounterRecord $counter): string => (string) $counter->attributes->get(HibpBreachedPasswords::OUTCOME),
        array_filter($telemetry->counters(), static fn (CounterRecord $counter): bool => $counter->name->value === HibpBreachedPasswords::CHECKS),
    ));
}

it('sends only the five-character prefix of the SHA-1, with the padding header and nothing else', function (): void {
    $service = new HibpRangeService;
    $service->breach(new Password(BREACHED));
    $hash = strtoupper(sha1(BREACHED));

    $breached = $service->breachedPasswords()->isBreached(new Password(BREACHED));

    $requests = $service->egress->requests();
    expect($breached)->toBeTrue()
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]->url)->toBe('https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5))
        ->and($requests[0]->hostClass->value)->toBe('breached_passwords')
        ->and(array_map(static fn (EgressHeader $header): array => [$header->name, $header->value], $requests[0]->headers))->toBe([['Add-Padding', 'true']]);

    $sent = $requests[0]->url.' '.implode(' ', array_map(static fn (EgressHeader $header): string => $header->name.': '.$header->value, $requests[0]->headers));

    expect($sent)->not->toContain(substr($hash, 5, 10))
        ->and(strtolower($sent))->not->toContain(strtolower(substr($hash, 5, 10)))
        ->and($sent)->not->toContain(BREACHED)
        ->and(strlen(substr($requests[0]->url, strlen(HibpBreachedPasswords::RANGE_URL))))->toBe(5);
});

it('never counts a padding line, which is seen 0 times, as a breach', function (): void {
    $service = new HibpRangeService;

    expect($service->breachedPasswords()->isBreached(new Password(HibpRangeService::PADDED_PASSWORD)))->toBeFalse()
        ->and(checkOutcomes($service->telemetry))->toBe(['clean']);
});

it('reads a count with leading zeros and lower case hex as the service never sends them', function (): void {
    $suffix = substr(strtoupper(sha1(BREACHED)), 5);
    $egress = new FakeEgressGateway;
    $egress->answerOthers(static fn (): EgressResponse => new EgressResponse(200, $suffix.":007\r\n"));
    $lower = new FakeEgressGateway;
    $lower->answerOthers(static fn (): EgressResponse => new EgressResponse(200, strtolower($suffix).":7\r\n"));

    expect(new HibpBreachedPasswords($egress, new FakeTelemetry)->isBreached(new Password(BREACHED)))->toBeTrue()
        ->and(fn (): bool => new HibpBreachedPasswords($lower, new FakeTelemetry)->isBreached(new Password(BREACHED)))->toThrow(BreachedPasswordsUnavailable::class);
});

it('fails closed on a status other than 200, a line it cannot read, an empty answer and a gateway failure, and counts each check', function (EgressResponse|int|null $answer): void {
    $egress = new FakeEgressGateway;
    $telemetry = new FakeTelemetry;

    if ($answer === null) {
        $egress->goDown();
    } else {
        $egress->answerOthers(static fn (): EgressResponse|int => $answer);
    }

    $passwords = new HibpBreachedPasswords($egress, $telemetry);

    expect(fn (): bool => $passwords->isBreached(new Password(BREACHED)))->toThrow(BreachedPasswordsUnavailable::class)
        ->and(checkOutcomes($telemetry))->toBe(['unavailable']);

    try {
        $passwords->isBreached(new Password(BREACHED));
    } catch (BreachedPasswordsUnavailable $unavailable) {
        expect($unavailable->getMessage())->not->toContain(BREACHED)
            ->and($unavailable->getMessage())->not->toContain(substr(strtoupper(sha1(BREACHED)), 5, 10))
            ->and($unavailable->getPrevious())->toBeNull();
    }
})->with([
    'service unavailable' => [new EgressResponse(503, 'busy')],
    'rate limited' => [new EgressResponse(429, '')],
    'not found' => [new EgressResponse(404, '')],
    'a line without a count' => [new EgressResponse(200, "0018A45C4D1DEF81644B54AB7F969B88D65\r\n")],
    'an HTML page' => [new EgressResponse(200, '<html>maintenance</html>')],
    'an empty answer' => [new EgressResponse(200, '')],
    'a redirect' => [302],
    'no answer' => [null],
]);

it('counts breached and clean checks under cms.outcome, never the password', function (): void {
    $service = new HibpRangeService;
    $service->breach(new Password(BREACHED));
    $passwords = $service->breachedPasswords();

    $passwords->isBreached(new Password(BREACHED));
    $passwords->isBreached(new Password('a long and quite unusual sentence'));

    expect(checkOutcomes($service->telemetry))->toBe(['breached', 'clean']);

    foreach ($service->telemetry->counters() as $counter) {
        foreach ($counter->attributes->attributes as $attribute) {
            expect((string) $attribute->value)->not->toContain(BREACHED)
                ->and((string) $attribute->value)->not->toContain(substr(strtoupper(sha1(BREACHED)), 0, 5));
        }
    }
});

it('is the BreachedPasswords the container gives, through the egress gateway, unless the application names another', function (): void {
    app()->instance(EgressGateway::class, new FakeEgressGateway);

    expect(app(BreachedPasswords::class))->toBeInstanceOf(HibpBreachedPasswords::class)
        ->and(config('cbox-cms.contracts.'.BreachedPasswords::class))->toBe(HibpBreachedPasswords::class);
});
