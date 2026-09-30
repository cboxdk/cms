<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Delivery;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Delivery\Boundary\DeliveryConfig;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliveryAuthorizer;
use Cbox\Cms\Core\Delivery\Domain\DeliverySource;
use Cbox\Cms\Core\Delivery\Domain\Dto\CacheDirective;
use Cbox\Cms\Core\Delivery\Domain\Dto\Delivery;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryAnswer;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliverySettings;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * The delivery API's values (PRD 8.10, 8.12): the cache directive, the settings and their reader,
 * an answer and a delivery, and the authorizer of its pipeline.
 */

it('keeps an answer out of shared caches, or for at least a second with optional stale seconds', function (): void {
    $none = CacheDirective::noStore();
    $shared = CacheDirective::shared(300);
    $stale = CacheDirective::shared(300, 30, 3600);

    expect([$none->shared, $none->maxAge, $none->stale()])->toBe([false, 0, false])
        ->and([$shared->shared, $shared->maxAge, $shared->staleWhileRevalidate, $shared->staleIfError, $shared->stale()])->toBe([true, 300, 0, 0, false])
        ->and([$stale->staleWhileRevalidate, $stale->staleIfError, $stale->stale()])->toBe([30, 3600, true])
        ->and(CacheDirective::shared(1, 0, 1)->stale())->toBeTrue()
        ->and(CacheDirective::shared(1, 1, 0)->stale())->toBeTrue();
});

it('refuses a shared lifetime below a second and negative stale seconds', function (int $maxAge, int $revalidate, int $error): void {
    CacheDirective::shared($maxAge, $revalidate, $error);
})->with([[0, 0, 0], [1, -1, 0], [1, 0, -1]])->throws(InvalidArgumentException::class);

it('holds the delivery settings within their ranges, with defaults', function (): void {
    $defaults = new DeliverySettings;
    $edges = new DeliverySettings(1, 0, 0);
    $most = new DeliverySettings(DeliverySettings::MAX_AGE_LIMIT, DeliverySettings::MAX_AGE_LIMIT, DeliverySettings::STALE_IF_ERROR_LIMIT);

    expect([$defaults->maxAge, $defaults->staleWhileRevalidate, $defaults->staleIfError])->toBe([300, 30, 3600])
        ->and([$edges->maxAge, $edges->staleWhileRevalidate, $edges->staleIfError])->toBe([1, 0, 0])
        ->and([$most->maxAge, $most->staleWhileRevalidate, $most->staleIfError])->toBe([86400, 86400, 3600]);
});

it('refuses delivery settings outside their ranges', function (int $maxAge, int $revalidate, int $error, string $message): void {
    expect(static fn (): DeliverySettings => new DeliverySettings($maxAge, $revalidate, $error))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [0, 0, 0, 'keeps an answer 1 to 86400 seconds, got 0'],
    [86401, 0, 0, 'keeps an answer 1 to 86400 seconds, got 86401'],
    [1, -1, 0, 'refetched 0 to 86400 seconds, got -1'],
    [1, 86401, 0, 'refetched 0 to 86400 seconds, got 86401'],
    [1, 0, -1, 'the origin fails 0 to 3600 seconds, got -1'],
    [1, 0, 3601, 'the origin fails 0 to 3600 seconds, got 3601'],
]);

it('reads the delivery settings from cbox-cms.delivery, with the defaults for what is left out', function (): void {
    $read = DeliveryConfig::read(new Repository(['cbox-cms' => ['delivery' => ['max_age_seconds' => 60, 'stale_while_revalidate_seconds' => 5, 'stale_if_error_seconds' => 90]]]));
    $defaults = DeliveryConfig::read(new Repository([]));

    expect([$read->maxAge, $read->staleWhileRevalidate, $read->staleIfError])->toBe([60, 5, 90])
        ->and([$defaults->maxAge, $defaults->staleWhileRevalidate, $defaults->staleIfError])->toBe([300, 30, 3600]);
});

it('refuses a delivery setting that is not a whole number, naming it', function (string $name): void {
    expect(static fn (): DeliverySettings => DeliveryConfig::read(new Repository(['cbox-cms' => ['delivery' => [$name => '60']]])))
        ->toThrow(InvalidArgumentException::class, sprintf('The setting cbox-cms.delivery.%s must be a whole number; it is string.', $name));
})->with(['max_age_seconds', 'stale_while_revalidate_seconds', 'stale_if_error_seconds']);

it('holds a record with its type and locale, or a problem, and not both, and names its format', function (): void {
    $problem = Problem::of(ErrorCode::PathNotFound, 'Nothing is placed at /nyheder/nothing.');
    $explanation = new PathExplanation(ResolveOutcome::NoPlacement, new SiteStep(new Host('north.example'), new Locale('da'), null, null, true));
    $record = new DeliveryAnswer(HttpStatus::Ok, '{}', new TypeName('app:article'), new Locale('da'));

    expect($record->format())->toBe(AnswerFormat::Record)
        ->and(DeliveryAnswer::problem($problem)->format())->toBe(AnswerFormat::Problem)
        ->and(DeliveryAnswer::problem($problem)->status)->toBe(HttpStatus::NotFound)
        ->and(DeliveryAnswer::problem($problem, $explanation)->format())->toBe(AnswerFormat::Explanation)
        ->and(static fn (): DeliveryAnswer => new DeliveryAnswer(HttpStatus::Ok))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): DeliveryAnswer => new DeliveryAnswer(HttpStatus::Ok, '{}', new TypeName('app:article')))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): DeliveryAnswer => new DeliveryAnswer(HttpStatus::Ok, '{}', null, new Locale('da')))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): DeliveryAnswer => new DeliveryAnswer(HttpStatus::Ok, null, new TypeName('app:article'), new Locale('da')))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): DeliveryAnswer => new DeliveryAnswer(HttpStatus::NotFound, '{}', new TypeName('app:article'), new Locale('da'), problem: $problem))->toThrow(InvalidArgumentException::class);
});

it('gives a delivery its content keys once each, sorted', function (): void {
    $entry = DependencyKey::entry(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003631'));
    $node = DependencyKey::node(NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003612'));
    $delivery = new Delivery(HttpStatus::Ok, AnswerFormat::Record, '{}', [$node, $entry, $node], CacheDirective::noStore(), DeliverySource::Origin);

    expect(array_map(static fn (DependencyKey $key): string => $key->toString(), $delivery->contentKeys))->toBe([$entry->toString(), $node->toString()]);
});

it('allows path.resolve to anyone and refuses every other read', function (): void {
    $authorizer = new DeliveryAuthorizer;
    $resolve = new ResolvePath(new Host('north.example'), new Locale('da'), new RequestPath('/'));

    expect($authorizer->authorize(AccessContext::anonymous(), new CommandName('path.resolve'), $resolve)->allowed())->toBeTrue()
        ->and($authorizer->authorize(AccessContext::anonymous(), new CommandName('probe.read'), new ReadProbe(1))->allowed())->toBeFalse()
        ->and($authorizer->authorize(AccessContext::anonymous(), new CommandName('probe.read'), $resolve)->reason)->toBe('The delivery API runs only path.resolve, not probe.read.')
        ->and($authorizer->authorize(AccessContext::anonymous(), new CommandName('path.resolve'), new ReadProbe(1))->allowed())->toBeFalse();
});
