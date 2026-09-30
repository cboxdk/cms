<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use LogicException;
use Override;

/**
 * REST with JSON and problem details (GUARDRAILS 2.1, PRD 8.8): the routes of the registry's REST
 * table, registered as an application registers them, and a POST of the command's document to the
 * command's route with the envelope in headers and the credential as the Bearer token. A rejection
 * is problem details with the catalog's HTTP status, a receipt is JSON with 200, and a receipt
 * that did not reach its wait level 202.
 */
final readonly class RestProfile implements SurfaceProfile
{
    private const string RECEIPT = 'application/json';

    #[Override]
    public function surface(): Surface
    {
        return Surface::Rest;
    }

    #[Override]
    public function prepare(TestCase $test, CompiledRegistry $registry, ContractKernel $kernel): void
    {
        $router = app(Router::class);
        RestRoutes::register($router, $registry);
        $router->getRoutes()->refreshNameLookups();
    }

    #[Override]
    public function credential(ContractKernel $kernel): TransportCredential
    {
        return $kernel->exposed->credential();
    }

    #[Override]
    public function send(TestCase $test, CompiledRegistry $registry, SurfaceCall $call): SurfaceAnswer
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$call->credential->reveal(),
        ];

        foreach ([OpenApiJson::IDEMPOTENCY_KEY => $call->key, OpenApiJson::DRY_RUN => $call->dryRun ? 'true' : 'false', OpenApiJson::WAIT_LEVEL => $call->waitLevel->value] as $header => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $header))] = $value;
        }

        $response = $test->call('POST', $this->route($registry, $call)->path, [], [], [], $server, $call->document);
        $type = explode(';', (string) $response->headers->get('Content-Type'))[0];
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return SurfaceAnswer::of(is_array($body) ? $body : [], sprintf('HTTP %d %s', $response->getStatusCode(), $type));
    }

    #[Override]
    public function commandPath(string $path): string
    {
        return $path;
    }

    #[Override]
    public function transport(Scenario $scenario, string $path): string
    {
        return match ($scenario) {
            Scenario::DocumentFieldError => $this->problem(ErrorCode::JsonInvalid),
            Scenario::FieldError => $this->problem(ErrorCode::ValidationFailed),
            Scenario::VersionConflict => $this->problem(ErrorCode::VersionConflict),
            Scenario::DryRun => 'HTTP 200 '.self::RECEIPT,
            Scenario::WaitTimeout => 'HTTP 202 '.self::RECEIPT,
        };
    }

    private function problem(ErrorCode $code): string
    {
        return sprintf('HTTP %d %s', $code->entry()->http->value, OpenApiJson::PROBLEM_MEDIA_TYPE);
    }

    private function route(CompiledRegistry $registry, SurfaceCall $call): RestRoute
    {
        foreach ($registry->rest as $route) {
            if ($route->kind === ActionKind::Write && $route->name->equals($call->command) && $route->version === $call->version) {
                return $route;
            }
        }

        throw new LogicException(sprintf('The REST table of the registry has no route for %s version %d.', $call->command->value, $call->version));
    }
}
