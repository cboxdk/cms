<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Tests\TestCase;

/**
 * How a surface contract test drives the reads of one surface (GUARDRAILS 2.1, 9): it serves the
 * registry's query actions on the surface as the application does, sends a read through the
 * surface's own transport, reads the answer, and says how the transport answers each scenario.
 * QuerySurfaceProfiles holds one per surface that serves reads; a query exposed on a surface
 * without one fails its test.
 */
interface QuerySurfaceProfile
{
    public function surface(): Surface;

    /**
     * Makes the application serve the registry's query actions on the surface, with the kernel's
     * QueryPipeline bound already.
     */
    public function prepare(TestCase $test, CompiledRegistry $registry): void;

    /**
     * The credential a caller of the surface sends: an agent's on MCP, the service actor's
     * elsewhere.
     */
    public function credential(QueryContractKernel $kernel): TransportCredential;

    /**
     * Sends the read through the surface and reads what it answered.
     */
    public function send(TestCase $test, CompiledRegistry $registry, QuerySurfaceCall $call): QueryAnswer;

    /**
     * The path the surface names a value of the query's document at, such as query.host, or the
     * document itself for ''.
     */
    public function queryPath(string $path): string;

    /**
     * The transport's signal for the scenario, as send() writes QueryAnswer::$transport.
     */
    public function transport(QueryScenario $scenario): string;
}
