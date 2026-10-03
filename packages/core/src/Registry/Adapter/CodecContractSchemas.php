<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Boundary\JsonSchemaNodes;
use Cbox\Cms\Core\Registry\Boundary\LocalFiles;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\ContractSchemas;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractShapes;
use Cbox\Cms\Core\Registry\Domain\Dto\PointSchemaDirectory;
use Cbox\Cms\Core\Registry\Domain\Dto\SchemaNode;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use JsonException;

/**
 * The JSON Schemas of the contracts, as cms:build checks the panel's contributions against them
 * (PRD 13.4): every command's and query's document from its generated codec, and every panel
 * point's props from the `<name>.v<version>.json` files in the directories the modules that
 * declare points register (PointSchemaDirectory::TAG). A directory that does not exist holds no
 * schema; a file that is not JSON fails the build as registry_invalid_manifest, naming the file.
 */
#[Internal]
final readonly class CodecContractSchemas implements ContractSchemas
{
    /** A point schema's file name: the point's name, `.v`, its version and `.json`. */
    private const string FILE = '/\A(?<name>.+)\.v(?<version>[1-9][0-9]{0,8})\.json\z/';

    /** @var list<PointSchemaDirectory> */
    private array $directories;

    public function __construct(
        private CommandCodecs $commands,
        private QueryCodecs $queries,
        PointSchemaDirectory ...$directories,
    ) {
        $this->directories = array_values($directories);
    }

    public function shapes(): ContractShapes
    {
        $commands = [];
        $queries = [];
        $points = [];
        $problems = [];

        foreach ($this->commands->all() as $codec) {
            $commands[$codec->command->value.'@'.$codec->version] = $this->node($codec->schema->json, sprintf('the schema of the command %s@%d', $codec->command->value, $codec->version), $problems);
        }

        foreach ($this->queries->all() as $codec) {
            $queries[$codec->name->value.'@'.$codec->version] = $this->node($codec->querySchema->json, sprintf('the schema of the query %s@%d', $codec->name->value, $codec->version), $problems);
        }

        foreach ($this->directories as $directory) {
            foreach (LocalFiles::files($directory->path, '.json') as $name => $path) {
                $id = $this->pointId($name);
                $json = $id instanceof PointId ? LocalFiles::read($path) : null;

                if ($id instanceof PointId && $json !== null) {
                    $points[$id->toString()] = $this->node($json, sprintf('the props schema %s', $path), $problems);
                }
            }
        }

        if ($problems !== []) {
            throw RegistryBuildFailed::with($problems);
        }

        return new ContractShapes(
            array_filter($commands, static fn (mixed $node): bool => $node instanceof SchemaNode),
            array_filter($queries, static fn (mixed $node): bool => $node instanceof SchemaNode),
            array_filter($points, static fn (mixed $node): bool => $node instanceof SchemaNode),
        );
    }

    /**
     * @param  list<BuildProblem>  $problems
     */
    private function node(string $json, string $what, array &$problems): ?SchemaNode
    {
        try {
            return JsonSchemaNodes::read($json);
        } catch (JsonException $invalid) {
            $problems[] = new BuildProblem(BuildErrorCode::InvalidManifest, sprintf('%s is not JSON: %s.', ucfirst($what), $invalid->getMessage()));

            return null;
        }
    }

    private function pointId(string $file): ?PointId
    {
        if (preg_match(self::FILE, $file, $parts) !== 1) {
            return null;
        }

        try {
            return new PointId(new PointName($parts['name']), (int) $parts['version']);
        } catch (InvalidPanelPoint) {
            return null;
        }
    }
}
