<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\FixtureSchema;
use Cbox\Cms\Generators\Schema\Domain\SchemaSource;
use Override;

/**
 * Schema files as a test declares them, by absolute path, without YAML.
 *
 * put() is a file with a valid schema; refuse() is a file that the YAML source cannot read into a
 * schema, with the failure it gives, such as generate_schema_syntax. A path it does not know is a
 * missing file: load() throws generate_schema_missing with the YAML source's message. Every path
 * asked for is kept in $loaded. SchemaSourceBehaviour holds it to YamlSchemaSource.
 */
final class FakeSchemaSource implements SchemaSource
{
    /** @var list<string> */
    public array $loaded = [];

    /** @var array<string, FixtureSchema|GenerationFailed> */
    private array $files = [];

    public function put(string $path, FixtureSchema $schema): void
    {
        $this->files[$path] = $schema;
    }

    public function refuse(string $path, GenerationFailed $failure): void
    {
        $this->files[$path] = $failure;
    }

    #[Override]
    public function load(string $path): FixtureSchema
    {
        $this->loaded[] = $path;
        $file = $this->files[$path] ?? null;

        if ($file === null) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
                'The schema file %s does not exist or cannot be read. Create it, or point cms.generators.schema at it.',
                $path,
            ));
        }

        if ($file instanceof GenerationFailed) {
            throw $file;
        }

        return $file;
    }
}
