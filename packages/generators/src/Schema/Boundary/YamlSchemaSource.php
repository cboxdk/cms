<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\FixtureSchema;
use Cbox\Cms\Generators\Schema\Domain\SchemaSource;
use Override;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the schema from a YAML file with symfony/yaml (PRD 11.12: the input is YAML).
 */
#[Internal]
final readonly class YamlSchemaSource implements SchemaSource
{
    public function __construct(private FixtureSchemaParser $parser) {}

    #[Override]
    public function load(string $path): FixtureSchema
    {
        $contents = $this->read($path);

        if ($contents === null) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
                'The schema file %s does not exist or cannot be read. Create it, or point cms.generators.schema at it.',
                $path,
            ));
        }

        try {
            $document = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $invalid) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaSyntax, sprintf(
                'The schema file %s is not valid YAML: %s',
                $path,
                $invalid->getMessage(),
            ), $invalid);
        }

        return $this->parser->parse($document, $path);
    }

    /**
     * The contents of a local file, or null when it cannot be read. Not file_get_contents, which
     * is reserved for the egress gateway because it also fetches URLs.
     */
    private function read(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $file = new SplFileObject($path, 'rb');
            $size = $file->getSize();
            $contents = $size === 0 ? '' : $file->fread($size);
        } catch (RuntimeException) {
            return null;
        }

        return is_string($contents) ? $contents : null;
    }
}
