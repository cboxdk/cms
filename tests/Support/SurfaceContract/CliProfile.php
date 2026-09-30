<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Cli\Boundary\CliCredential;
use Cbox\Cms\Cli\Domain\CliActions;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Override;

/**
 * The CLI with exit codes from the error catalog (GUARDRAILS 2.1): cms:run with CliActions over
 * the registry, the command's name, version and document as its arguments, the envelope as its
 * options and --json, and the service actor's credential as the configured
 * cbox-cms.cli.credential. A rejection exits with the catalog's exit code and prints the problem;
 * a receipt exits 0 and prints the receipt.
 */
final readonly class CliProfile implements SurfaceProfile
{
    #[Override]
    public function surface(): Surface
    {
        return Surface::Cli;
    }

    #[Override]
    public function prepare(TestCase $test, CompiledRegistry $registry, ContractKernel $kernel): void
    {
        app()->instance(CliActions::class, new CliActions($registry));
        config()->set(CliCredential::CONFIG_KEY, $this->credential($kernel)->reveal());
    }

    #[Override]
    public function credential(ContractKernel $kernel): TransportCredential
    {
        return $kernel->exposed->credential();
    }

    #[Override]
    public function send(TestCase $test, CompiledRegistry $registry, SurfaceCall $call): SurfaceAnswer
    {
        $status = Artisan::call('cms:run', [
            'name' => $call->command->value,
            'version' => (string) $call->version,
            'document' => $call->document,
            '--idempotency-key' => $call->key,
            '--wait-level' => $call->waitLevel->value,
            '--dry-run' => $call->dryRun,
            '--json' => true,
        ]);

        $document = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        return SurfaceAnswer::of(is_array($document) ? $document : [], 'exit '.$status);
    }

    #[Override]
    public function commandPath(string $path): string
    {
        return $path;
    }

    #[Override]
    public function transport(Scenario $scenario, string $path): string
    {
        $exit = match ($scenario) {
            Scenario::DocumentFieldError => ErrorCode::JsonInvalid->entry()->exit,
            Scenario::FieldError => ErrorCode::ValidationFailed->entry()->exit,
            Scenario::VersionConflict => ErrorCode::VersionConflict->entry()->exit,
            Scenario::DryRun => ErrorCode::DryRun->entry()->exit,
            Scenario::WaitTimeout => ExitCode::Ok,
        };

        return 'exit '.$exit->value;
    }
}
