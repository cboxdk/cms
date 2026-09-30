<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Tests\TestCase;
use Override;
use stdClass;

/**
 * The Inertia profile, with redirects and errors in page props (GUARDRAILS 2.1): the workbench's
 * test page and the profile's route below it, with InertiaActions over the registry, and a form
 * post of the envelope and the command's document from the page, as an Inertia client sends it
 * with the credential as the Bearer token. Every answer is a redirect back to the page; the page
 * it renders then carries the receipt in its flash, and a rejection's field errors in the errors
 * prop at their path below `command` and its catalog code in the problem prop.
 */
final readonly class InertiaProfile implements SurfaceProfile
{
    public const string PAGE = '/workbench/inertia';

    #[Override]
    public function surface(): Surface
    {
        return Surface::Inertia;
    }

    #[Override]
    public function prepare(TestCase $test, CompiledRegistry $registry, ContractKernel $kernel): void
    {
        app()->instance(InertiaActions::class, new InertiaActions($registry));
    }

    #[Override]
    public function credential(ContractKernel $kernel): TransportCredential
    {
        return $kernel->exposed->credential();
    }

    #[Override]
    public function send(TestCase $test, CompiledRegistry $registry, SurfaceCall $call): SurfaceAnswer
    {
        $envelope = new stdClass;
        $envelope->idempotency_key = $call->key;
        $envelope->dry_run = $call->dryRun;
        $envelope->wait_level = $call->waitLevel->value;

        $response = $test->withHeaders(['X-Inertia' => 'true', 'Authorization' => 'Bearer '.$call->credential->reveal()])
            ->from(self::PAGE)
            ->postJson(sprintf('%s/commands/%s/v%d', self::PAGE, $call->command->value, $call->version), [
                'envelope' => $envelope,
                'command' => json_decode($call->document, false, 512, JSON_THROW_ON_ERROR),
            ]);

        $page = $test->withHeaders(['X-Inertia' => 'true'])->get(self::PAGE)->assertOk()->json();
        $page = is_array($page) ? $page : [];
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];
        $flash = is_array($page['flash'] ?? null) ? $page['flash'] : [];
        $receipt = is_array($flash[InertiaOutcome::RECEIPT] ?? null) ? $flash[InertiaOutcome::RECEIPT] : [];
        $errors = is_array($props['errors'] ?? null) ? array_map(strval(...), array_keys($props['errors'])) : [];
        $location = parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);

        $answer = SurfaceAnswer::of($receipt, sprintf('redirect %d to %s, errors prop [%s]', $response->getStatusCode(), is_string($location) ? $location : '-', implode(', ', $errors)));

        return is_array($props[InertiaOutcome::PROBLEM_PROP] ?? null) ? $answer->withProblem($props[InertiaOutcome::PROBLEM_PROP]) : $answer;
    }

    #[Override]
    public function commandPath(string $path): string
    {
        return 'command.'.$path;
    }

    #[Override]
    public function transport(Scenario $scenario, string $path): string
    {
        $errors = match ($scenario) {
            Scenario::DocumentFieldError, Scenario::FieldError => $this->commandPath($path),
            default => '',
        };

        return sprintf('redirect %d to %s, errors prop [%s]', InertiaOutcome::STATUS, self::PAGE, $errors);
    }
}
