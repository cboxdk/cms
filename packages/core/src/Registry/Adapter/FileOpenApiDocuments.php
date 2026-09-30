<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DescribedRoute;
use Cbox\Cms\Core\Registry\Domain\Dto\OpenApiDocument;
use Cbox\Cms\Core\Registry\Domain\OpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use RuntimeException;
use SplFileObject;
use UnexpectedValueException;

/**
 * The OpenAPI document of the REST surface as the file FILE in the registry cache's directory,
 * bootstrap/cache/cms/openapi.json in an application (GUARDRAILS 2.1, PRD 8.8).
 *
 * describe() takes each route's JSON Schemas from the codecs the container has under
 * CommandCodecs::TAG and QueryCodecs::TAG, and the receipt's and the problem details' from the
 * kernel's schemas in the contracts module, receipt.v1.json and problem.v1.json. write() writes a
 * temporary file next to FILE and renames it into place, so a reader sees the old or the new
 * document, never half of one; the registry cache keeps FILE and its temporary file when it
 * removes the files it does not write. It refuses a directory that names a stream wrapper before
 * it touches it (GUARDRAILS 3).
 */
#[Internal]
final readonly class FileOpenApiDocuments implements OpenApiDocuments
{
    /** The document's file in the directory. */
    public const string FILE = 'openapi.json';

    /** The kernel's JSON Schemas, relative to the root of cboxdk/cms. */
    public const string KERNEL_SCHEMAS = 'packages/contracts/resources/schemas';

    /**
     * @param  string|null  $root  the root of cboxdk/cms, or null for the one this file is in
     */
    public function __construct(
        private string $directory,
        private CommandCodecs $commands,
        private QueryCodecs $queries,
        private OpenApiJson $json = new OpenApiJson,
        private ?string $root = null,
    ) {}

    public function describe(CompiledRegistry $registry): OpenApiDocument
    {
        $described = [];
        $problems = [];

        foreach ($registry->rest as $route) {
            $class = $registry->action($route->name, $route->version)->class ?? 'an action the registry does not list';

            if ($route->kind === ActionKind::Write) {
                $codec = $this->commands->find($route->name, $route->version);

                if ($codec instanceof CommandCodec) {
                    $described[] = new DescribedRoute($route, $codec->schema);
                } else {
                    $problems[] = new BuildProblem(BuildErrorCode::SurfaceWithoutCodec, sprintf(
                        'Action %s is exposed on REST, but no codec reads version %d of the command %s, so %s %s cannot be described or served. Register its CommandCodec, with the command\'s JSON Schema, under the container tag cbox-cms.command-codecs.',
                        $class,
                        $route->version,
                        $route->name->value,
                        $route->method->value,
                        $route->path,
                    ));
                }

                continue;
            }

            $codec = $this->queries->find($route->name, $route->version);

            if ($codec instanceof QueryCodec) {
                $described[] = new DescribedRoute($route, $codec->querySchema, $codec->resultSchema);
            } else {
                $problems[] = new BuildProblem(BuildErrorCode::SurfaceWithoutCodec, sprintf(
                    'Action %s is exposed on REST, but no codec reads version %d of the query %s, so %s %s cannot be described or served. Register its QueryCodec, with the JSON Schemas of the query and its result, under the container tag cbox-cms.query-codecs.',
                    $class,
                    $route->version,
                    $route->name->value,
                    $route->method->value,
                    $route->path,
                ));
            }
        }

        if ($problems !== []) {
            throw RegistryBuildFailed::with($problems);
        }

        return $this->json->encode($described, $this->kernelSchema('receipt.v1.json'), $this->kernelSchema('problem.v1.json'));
    }

    public function write(OpenApiDocument $document): void
    {
        $path = $this->directory.'/'.self::FILE;

        if (LocalPath::namesStreamWrapper($this->directory)) {
            throw RegistryCacheUnwritable::streamWrapper($this->directory);
        }

        $temporary = sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(8)));
        $failure = $this->attempt(static fn (): bool => file_put_contents($temporary, $document->json) === strlen($document->json), 'the file could not be written')
            ?? $this->attempt(static fn (): bool => rename($temporary, $path), 'the file could not be moved into place');

        if ($failure !== null) {
            $this->attempt(static fn (): bool => ! is_file($temporary) || unlink($temporary), 'the temporary file could not be removed');

            throw RegistryCacheUnwritable::at($path, $failure);
        }
    }

    /**
     * @throws UnexpectedValueException when the installation lacks the schema, which it ships
     */
    private function kernelSchema(string $file): JsonSchema
    {
        $path = ($this->root ?? dirname(__DIR__, 5)).'/'.self::KERNEL_SCHEMAS.'/'.$file;
        $json = null;

        if (! LocalPath::namesStreamWrapper($path) && is_file($path)) {
            $this->attempt(static function () use ($path, &$json): bool {
                try {
                    $handle = new SplFileObject($path, 'r');
                    $size = $handle->getSize();
                    $read = $size > 0 ? $handle->fread($size) : '';
                } catch (RuntimeException) {
                    return false;
                }

                $json = $read === false ? null : $read;

                return $read !== false;
            }, 'the file could not be read');
        }

        if (! is_string($json)) {
            throw new UnexpectedValueException(sprintf('The kernel schema %s of cboxdk/cms does not exist or cannot be read. Install cboxdk/cms again.', $path));
        }

        return new JsonSchema($json);
    }

    /**
     * Runs a filesystem call and turns its warning into the reason it failed.
     *
     * @param  callable(): bool  $operation
     * @return string|null null when it succeeded, otherwise why it failed
     */
    private function attempt(callable $operation, string $fallback): ?string
    {
        $warning = null;

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $succeeded = $operation();
        } finally {
            restore_error_handler();
        }

        return $succeeded ? null : ($warning ?? $fallback);
    }
}
