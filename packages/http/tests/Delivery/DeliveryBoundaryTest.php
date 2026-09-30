<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Delivery;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliverySource;
use Cbox\Cms\Core\Delivery\Domain\Dto\CacheDirective;
use Cbox\Cms\Core\Delivery\Domain\Dto\Delivery;
use Cbox\Cms\Http\Delivery\Boundary\DeliveryInput;
use Cbox\Cms\Http\Delivery\Boundary\DeliveryOutput;
use Cbox\Cms\Http\Delivery\DeliveryRoutes;
use Cbox\Cms\Http\Delivery\ResolveController;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/*
 * The delivery API's surface (PRD 8.10): the route, what a request is read as, and how an answer
 * is written, with its cache headers and content keys.
 */

const BOUNDARY_ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000003631';

const BOUNDARY_NODE = '01936f5e-8a2b-7c3d-9e4f-000000003612';

function boundaryDelivery(CacheDirective $cache, AnswerFormat $format = AnswerFormat::Record, DeliverySource $source = DeliverySource::Origin, bool $keys = true): Delivery
{
    return new Delivery(
        $format === AnswerFormat::Problem ? HttpStatus::NotFound : HttpStatus::Ok,
        $format,
        '{"data":{}}',
        $keys ? [DependencyKey::node(NodeId::fromString(BOUNDARY_NODE)), DependencyKey::entry(EntryId::fromString(BOUNDARY_ENTRY))] : [],
        $cache,
        $source,
    );
}

it('registers GET v1/resolve, or the path given, to the controller, by its name', function (): void {
    $router = app(Router::class);
    $route = DeliveryRoutes::register($router);
    $other = DeliveryRoutes::register($router, '/delivery/resolve/');

    expect($route->uri())->toBe('v1/resolve')
        ->and($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->getName())->toBe(DeliveryRoutes::NAME)
        ->and($route->getActionName())->toBe(ResolveController::class)
        ->and($other->uri())->toBe('delivery/resolve');
});

it('reads the known parameters and leaves every other parameter and the credential out of an ordinary request', function (): void {
    $request = Request::create('/v1/resolve', 'GET', ['site' => 'north.example', 'locale' => 'da', 'path' => '/nyheder/harbour', 'utm_source' => 'mail']);
    $request->headers->set('Authorization', 'Bearer a-token');
    $read = DeliveryInput::request($request);

    expect([$read->site, $read->locale, $read->path, $read->debug, $read->credential])->toBe(['north.example', 'da', '/nyheder/harbour', null, null]);
});

it('reads the Bearer credential only for a request that asks for the explanation', function (): void {
    $request = Request::create('/v1/resolve', 'GET', ['site' => 'north.example', 'debug' => '1']);
    $request->headers->set('Authorization', 'Bearer a-token');
    $read = DeliveryInput::request($request);

    expect([$read->debug, $read->credential?->reveal(), $read->locale, $read->path])->toBe(['1', 'a-token', null, null]);
});

it('reads a parameter given as an array as malformed', function (): void {
    $read = DeliveryInput::request(Request::create('/v1/resolve', 'GET', ['site' => ['north.example'], 'locale' => ['da', 'en'], 'path' => '/', 'debug' => ['1']]));

    expect([$read->site, $read->locale, $read->path, $read->debug])->toBe(["\0", "\0", '/', "\0"])
        ->and($read->credential)->toBeNull();
});

it('writes a shared answer with its lifetime, its stale seconds and its content keys, and no cookie or Vary', function (): void {
    $response = DeliveryOutput::response(boundaryDelivery(CacheDirective::shared(300, 30, 3600)));
    $directives = array_map(trim(...), explode(',', (string) $response->headers->get('Cache-Control')));
    sort($directives);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('{"data":{}}')
        ->and($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($directives)->toBe(['max-age=0', 'public', 's-maxage=300', 'stale-if-error=3600', 'stale-while-revalidate=30'])
        ->and($response->headers->get(DeliveryOutput::SURROGATE_KEY))->toBe('e-'.BOUNDARY_ENTRY.' n-'.BOUNDARY_NODE)
        ->and($response->headers->get(DeliveryOutput::SOURCE))->toBe('miss')
        ->and($response->headers->has('Vary'))->toBeFalse()
        ->and($response->headers->getCookies())->toBe([]);
});

it('writes only the stale directives an answer has', function (int $revalidate, int $error, string $expected): void {
    $directives = array_map(trim(...), explode(',', (string) DeliveryOutput::response(boundaryDelivery(CacheDirective::shared(60, $revalidate, $error)))->headers->get('Cache-Control')));
    sort($directives);

    expect(implode(', ', $directives))->toBe($expected);
})->with([
    [0, 0, 'max-age=0, public, s-maxage=60'],
    [15, 0, 'max-age=0, public, s-maxage=60, stale-while-revalidate=15'],
    [0, 90, 'max-age=0, public, s-maxage=60, stale-if-error=90'],
]);

it('writes an answer no shared cache may keep as private and no-store, a problem as problem details, and a fragment as a hit', function (): void {
    $response = DeliveryOutput::response(boundaryDelivery(CacheDirective::noStore(), AnswerFormat::Problem, DeliverySource::Fragment, keys: false));
    $explained = DeliveryOutput::response(boundaryDelivery(CacheDirective::noStore(), AnswerFormat::Explanation));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->has(DeliveryOutput::SURROGATE_KEY))->toBeFalse()
        ->and($response->headers->get(DeliveryOutput::SOURCE))->toBe('hit')
        ->and($explained->headers->get('Content-Type'))->toBe('application/json');
});
