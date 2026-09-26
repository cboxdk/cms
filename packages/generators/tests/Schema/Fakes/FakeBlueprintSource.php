<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema\Fakes;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\ExtensionBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use LogicException;
use Override;

/**
 * Blueprint files as a test declares them, below schema roots, without YAML or JSON Schema.
 *
 * root() makes a root exist; a root it was not given is a missing directory, and read() reports it
 * with the YAML source's message. put() is a file that reads into a blueprint, which must name
 * that file as its location; refuse() is a file that the YAML source rejects, with its problems.
 * read() collects the files of the roots it is given in sorted path order and fails with every
 * problem at once, as the YAML source does. Every call is kept in $reads. BlueprintSourceBehaviour
 * holds it to YamlBlueprintSource.
 */
final class FakeBlueprintSource implements BlueprintSource
{
    /** @var list<list<SchemaRoot>> */
    public array $reads = [];

    /** @var array<string, array<string, TypeBlueprint|ExtensionBlueprint|list<GenerationProblem>>> by root path, then path below it */
    private array $roots = [];

    public function root(SchemaRoot $root): SchemaRoot
    {
        $this->roots[$root->path()] ??= [];

        return $root;
    }

    public function put(SchemaRoot $root, string $path, TypeBlueprint|ExtensionBlueprint $blueprint): void
    {
        if ($blueprint->location->file !== $root->file($path) || ! $blueprint->owner->equals($root->owner)) {
            throw new LogicException(sprintf('The blueprint is located in %s of %s, not in %s of %s.', $blueprint->location->file, $blueprint->owner->value, $root->file($path), $root->owner->value));
        }

        $this->root($root);
        $this->roots[$root->path()][$path] = $blueprint;
    }

    /**
     * @param  non-empty-list<GenerationProblem>  $problems
     */
    public function refuse(SchemaRoot $root, string $path, array $problems): void
    {
        $this->root($root);
        $this->roots[$root->path()][$path] = $problems;
    }

    #[Override]
    public function read(array $roots): Blueprints
    {
        $this->reads[] = $roots;
        $problems = [];
        $files = [];

        foreach ($roots as $root) {
            if (! array_key_exists($root->path(), $this->roots)) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf(
                    'The schema root %s of %s does not exist or cannot be read. Create the directory, or remove the root.',
                    $root->path(),
                    $root->owner->value,
                ));

                continue;
            }

            foreach ($this->roots[$root->path()] as $path => $file) {
                $files[$root->file($path)] = $file;
            }
        }

        ksort($files, SORT_STRING);
        $types = [];
        $extensions = [];

        foreach ($files as $file) {
            if ($file instanceof TypeBlueprint) {
                $types[] = $file;
            } elseif ($file instanceof ExtensionBlueprint) {
                $extensions[] = $file;
            } else {
                array_push($problems, ...$file);
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return new Blueprints($types, $extensions);
    }
}
