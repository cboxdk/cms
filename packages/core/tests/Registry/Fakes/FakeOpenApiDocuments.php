<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fakes;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\OpenApiDocument;
use Cbox\Cms\Core\Registry\Domain\OpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Override;

/**
 * The OpenAPI documents in memory. It knows the commands and queries whose codecs a test gives it,
 * as name@version; describe() refuses a REST route of any other with
 * registry_surface_without_codec, as FileOpenApiDocuments does, and otherwise gives a document with
 * the OpenAPI version and each route's path and method, which is all an action test reads.
 * refuseWrites() scripts the file's write failing. OpenApiDocumentsBehaviour holds it to
 * FileOpenApiDocuments.
 */
final class FakeOpenApiDocuments implements OpenApiDocuments
{
    /** The document the last write put in place, or null. */
    public ?OpenApiDocument $written = null;

    /** @var list<string> the commands and queries with codecs, as name@version */
    private readonly array $codecs;

    private ?string $refusal = null;

    public function __construct(string ...$codecs)
    {
        $this->codecs = array_values($codecs);
    }

    #[Override]
    public function describe(CompiledRegistry $registry): OpenApiDocument
    {
        $paths = [];
        $problems = [];

        foreach ($registry->rest as $route) {
            if (! in_array($this->key($route->name, $route->version), $this->codecs, true)) {
                $problems[] = new BuildProblem(BuildErrorCode::SurfaceWithoutCodec, sprintf('Action %s is exposed on REST, but no codec reads version %d of the %s %s.', $registry->action($route->name, $route->version)->class ?? 'unknown', $route->version, $route->kind->input(), $route->name->value));

                continue;
            }

            $paths[$route->path] = [strtolower($route->method->value) => ['operationId' => sprintf('%s.%s.v%d', $route->kind->input(), $route->name->value, $route->version)]];
        }

        if ($problems !== []) {
            throw RegistryBuildFailed::with($problems);
        }

        ksort($paths, SORT_STRING);

        return new OpenApiDocument(json_encode(['openapi' => '3.1.1', 'paths' => (object) $paths], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    #[Override]
    public function write(OpenApiDocument $document): void
    {
        if ($this->refusal !== null) {
            throw RegistryCacheUnwritable::at('/srv/app/bootstrap/cache/cms/openapi.json', $this->refusal);
        }

        $this->written = $document;
    }

    public function refuseWrites(string $reason = 'Permission denied'): void
    {
        $this->refusal = $reason;
    }

    private function key(CommandName $name, int $version): string
    {
        return $name->value.'@'.$version;
    }
}
