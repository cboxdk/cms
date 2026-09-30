<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Tests\TestCase;

/**
 * How a surface contract test drives one surface (GUARDRAILS 2.1, 9): it serves the registry's
 * actions on the surface as the application does, sends a call through the surface's own
 * transport, reads the answer, and says how the transport answers each scenario ("Transportprofiler":
 * REST with JSON and problem details, Inertia with redirects and errors in page props, MCP with
 * tool errors, CLI with exit codes from the error catalog). SurfaceProfiles holds one per surface;
 * an action exposed on a surface without one fails the suite.
 */
interface SurfaceProfile
{
    public function surface(): Surface;

    /**
     * Makes the application serve the registry's actions on the surface, with the kernel's
     * RunExposedCommand bound already.
     */
    public function prepare(TestCase $test, CompiledRegistry $registry, ContractKernel $kernel): void;

    /**
     * The credential a caller of the surface sends: an agent's on MCP, the service actor's
     * elsewhere.
     */
    public function credential(ContractKernel $kernel): TransportCredential;

    /**
     * Sends the call through the surface and reads what it answered.
     */
    public function send(TestCase $test, CompiledRegistry $registry, SurfaceCall $call): SurfaceAnswer;

    /**
     * The path the surface names a value of the command's document at, such as fields.label or
     * command.fields.label.
     */
    public function commandPath(string $path): string;

    /**
     * The transport's signal for the scenario, as send() writes SurfaceAnswer::$transport; a
     * field error names the path given.
     */
    public function transport(Scenario $scenario, string $path): string;
}
