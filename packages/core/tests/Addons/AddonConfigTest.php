<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Addons;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Addons\Boundary\AddonConfig;
use Cbox\Cms\Core\Addons\Domain\Dto\ServiceActors;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * The installation's settings for its addons, cbox-cms.addons: the service actor of each addon by
 * its namespace (PRD 13.1, invariant 21).
 */

it('reads the service actor of each addon by its namespace', function (): void {
    $actors = AddonConfig::read(new Repository(['cbox-cms' => ['addons' => ['service_actors' => [
        'reviews' => '01936f5e-8a2b-7c3d-9e4f-00000000a001',
    ]]]]));

    expect($actors->of(new AddonNamespace('reviews')))->toEqual(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000a001'))
        ->and($actors->of(new AddonNamespace('glossary')))->toBeNull();
});

it('has no service actors when nothing is configured', function (): void {
    expect(AddonConfig::read(new Repository([])))->toEqual(new ServiceActors);
});

it('refuses service actors that are not a map from addon namespaces to actor ids', function (mixed $value, string $message): void {
    expect(static fn (): ServiceActors => AddonConfig::read(new Repository(['cbox-cms' => ['addons' => ['service_actors' => $value]]])))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a string' => ['reviews', 'The setting cbox-cms.addons.service_actors must be a map from addon namespaces to actor ids; it is string.'],
    'a reserved namespace' => [['app' => '01936f5e-8a2b-7c3d-9e4f-00000000a001'], 'The setting cbox-cms.addons.service_actors names "app", which is not an addon namespace.'],
    'a list' => [['01936f5e-8a2b-7c3d-9e4f-00000000a001'], 'The setting cbox-cms.addons.service_actors names "0", which is not an addon namespace.'],
    'an id that is not a string' => [['reviews' => 7], 'The setting cbox-cms.addons.service_actors.reviews must be an actor id; it is int.'],
    'an id that is not a UUIDv7' => [['reviews' => 'reviewer'], 'The setting cbox-cms.addons.service_actors.reviews is not an actor id.'],
]);

it('binds the service actors from the configuration', function (): void {
    config()->set('cbox-cms.addons.service_actors', ['reviews' => '01936f5e-8a2b-7c3d-9e4f-00000000a001']);

    expect(app(ServiceActors::class)->of(new AddonNamespace('reviews')))->toEqual(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000a001'));
});

it('keeps the cause of a refused namespace or id, with the exception code 0', function (array $value): void {
    $refusal = null;

    try {
        AddonConfig::read(new Repository(['cbox-cms' => ['addons' => ['service_actors' => $value]]]));
    } catch (InvalidArgumentException $refused) {
        $refusal = $refused;
    }

    expect($refusal?->getCode())->toBe(0)
        ->and($refusal?->getPrevious())->not->toBeNull();
})->with([
    'a reserved namespace' => [['app' => '01936f5e-8a2b-7c3d-9e4f-00000000a001']],
    'an id that is not a UUIDv7' => [['reviews' => 'reviewer']],
]);
