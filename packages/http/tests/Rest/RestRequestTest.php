<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Http\Rest\Boundary\RestInputRefused;
use Cbox\Cms\Http\Rest\Boundary\RestRequest;
use Cbox\Cms\Http\Tests\Rest\Support\RestWorld;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use PHPUnit\Framework\Assert;

/*
 * What RestRequest reads from a request of a compiled route, beyond what the HTTP tests reach: a
 * header of the envelope sent twice, the defaults a route needs, and the call it builds.
 */

/**
 * A POST to probe.rename's route with the headers given, each a list of values.
 *
 * @param  array<string, list<string>>  $headers
 * @param  array<string, string>  $defaults
 */
function restRequest(array $headers, array $defaults = [RestRequest::NAME => 'probe.rename', RestRequest::VERSION => '1']): Request
{
    $request = Request::create('/v1/commands/probe.rename/v1', 'POST', [], [], [], [], '{"a":1}');

    foreach ($headers as $name => $values) {
        $request->headers->set($name, $values);
    }

    $route = new Route('POST', '/v1/commands/probe.rename/v1', []);

    foreach ($defaults as $key => $value) {
        $route->defaults($key, $value);
    }

    $route->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    return $request;
}

function restReader(): RestRequest
{
    return new RestRequest(ExposedWorld::codecs(), RestWorld::queryCodecs(), new EnvelopeCodecV1);
}

function restRefusal(Request $request): CatalogError
{
    try {
        restReader()->command($request);
    } catch (RestInputRefused $refused) {
        return $refused->error;
    }

    Assert::fail('The request was read.');
}

it('reads the call of a command: the surface, the credential, the envelope of the headers, the codec of the route and the body', function (): void {
    $call = restReader()->command(restRequest([
        'Authorization' => ['Bearer abc'],
        OpenApiJson::IDEMPOTENCY_KEY => ['key-1'],
        OpenApiJson::WAIT_LEVEL => ['edge'],
        OpenApiJson::DRY_RUN => ['false'],
    ]));

    expect($call)->toBeInstanceOf(ExposedCall::class)
        ->and($call->surface->value)->toBe('rest')
        ->and($call->credential?->reveal())->toBe('abc')
        ->and($call->envelope->idempotencyKey->value)->toBe('key-1')
        ->and($call->envelope->waitLevel->value)->toBe('edge')
        ->and($call->envelope->dryRun)->toBeFalse()
        ->and($call->envelope->correlationId)->toBeNull()
        ->and($call->codec)->toEqual(ExposedWorld::codec())
        ->and($call->command)->toBe('{"a":1}');
});

it('refuses a header of the envelope sent more than once', function (string $header, ErrorCode $code): void {
    $headers = [OpenApiJson::IDEMPOTENCY_KEY => ['key-1'], $header => ['one', 'two']];

    $error = restRefusal(restRequest($headers));

    expect($error->code)->toBe($code)
        ->and($error->path)->toBeNull()
        ->and($error->message)->toBe(sprintf('The header %s is sent 2 times. Send it once.', $header));
})->with([
    'the idempotency key' => [OpenApiJson::IDEMPOTENCY_KEY, ErrorCode::IdempotencyKeyRequired],
    'the wait level' => [OpenApiJson::WAIT_LEVEL, ErrorCode::RequestHeaderInvalid],
    'the dry run' => [OpenApiJson::DRY_RUN, ErrorCode::RequestHeaderInvalid],
    'the correlation id' => [OpenApiJson::CORRELATION_ID, ErrorCode::RequestHeaderInvalid],
]);

it('reads a dry run of true', function (): void {
    expect(restReader()->command(restRequest([OpenApiJson::IDEMPOTENCY_KEY => ['k'], OpenApiJson::DRY_RUN => ['true']]))->envelope->dryRun)->toBeTrue();
});

/**
 * @param  array<string, string>  $defaults
 */
function restUnregistered(array $defaults): ExposedCall
{
    return restReader()->command(restRequest([OpenApiJson::IDEMPOTENCY_KEY => ['k']], $defaults));
}

it('refuses a route that was not registered with the name and version of its command', function (array $defaults): void {
    $typed = [];

    foreach ($defaults as $key => $value) {
        Assert::assertIsString($value);
        $typed[(string) $key] = $value;
    }

    expect(static fn (): ExposedCall => restUnregistered($typed))
        ->toThrow(LogicException::class, 'The REST route has no command or query in its defaults cbox_cms_name and cbox_cms_version; register it with RestRoutes::register().');
})->with([
    'no defaults' => [[]],
    'no version' => [[RestRequest::NAME => 'probe.rename']],
    'version 0' => [[RestRequest::NAME => 'probe.rename', RestRequest::VERSION => '0']],
    'a version that is not a number' => [[RestRequest::NAME => 'probe.rename', RestRequest::VERSION => 'one']],
]);
