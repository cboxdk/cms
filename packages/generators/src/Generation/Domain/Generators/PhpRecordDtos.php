<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Generators;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Codec\Domain\PhpDtoEmitter;
use Cbox\Cms\Generators\Codec\Domain\RecordContracts;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Generation\Domain\Generator;
use Override;

/**
 * The record DTOs and their JSON codecs (PRD 8.9, GUARDRAILS 2.2): for each type and the record
 * contract version 1, a final readonly DTO per object of the record in `Domain/Dto` below the PHP
 * directory, and a codec in `Boundary`, which reads a document's `mixed` (RecordContracts,
 * PhpDtoEmitter, PhpCodecEmitter).
 *
 * Two properties of one object, or two classes of the DTOs, that get the same PHP name are refused
 * with generate_name_collision, never renamed, so the generated names follow from the handles alone.
 */
#[Internal]
final readonly class PhpRecordDtos implements Generator
{
    /**
     * What the DTOs and codecs hold for each core field type (RecordContracts::FIELD_TYPES). The
     * generator-coverage test holds the keys to the field types of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array FIELD_TYPES = RecordContracts::FIELD_TYPES;

    /**
     * What the DTOs hold for a blueprint file of each kind. The generator-coverage test holds the
     * keys to the kinds of the installed blueprint.v1.json.
     *
     * @var array<string, string>
     */
    public const array KINDS = [
        'extension' => 'its fields in the ext object of the record of the type it extends, under its namespace',
        'type' => 'a record DTO and a codec per contract version, with a DTO per group',
    ];

    #[Override]
    public function directory(GenerationTarget $target): string
    {
        return $target->phpDirectory;
    }

    #[Override]
    public function generate(CompiledSchema $schema, GenerationTarget $target): array
    {
        $location = new PhpLocation($target->phpDirectory, $target->phpNamespace);
        $contracts = array_map(RecordContracts::of(...), $schema->types);
        $this->assertNames($contracts);
        $files = [];

        foreach ($contracts as $contract) {
            array_push(
                $files,
                ...PhpDtoEmitter::emit($contract, $location->below('Domain', 'Dto')),
                ...[PhpCodecEmitter::emit($contract, $location->below('Domain', 'Dto'), $location->below('Boundary'))],
            );
        }

        return $files;
    }

    /**
     * @param  list<CodecContract>  $contracts
     *
     * @throws GenerationFailed with GenerateErrorCode::NameCollision
     */
    private function assertNames(array $contracts): void
    {
        $problems = [];
        $classes = [];

        foreach ($contracts as $contract) {
            foreach ($contract->root->objects() as $object) {
                if (isset($classes[$object->className])) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::NameCollision, sprintf(
                        'The records %s and %s both have a DTO class %s. Rename a type or a group so their handles differ in more than underscores.',
                        $classes[$object->className],
                        $contract->root->className,
                        $object->className,
                    ));
                }

                $classes[$object->className] ??= $contract->root->className;
                array_push($problems, ...$this->propertyProblems($object));
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }
    }

    /**
     * @return list<GenerationProblem>
     */
    private function propertyProblems(CodecObject $object): array
    {
        $problems = [];
        $keys = [];

        foreach ($object->properties as $property) {
            if (isset($keys[$property->name])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::NameCollision, sprintf(
                    'The fields %s and %s of %s both become the property $%s. Rename one so their handles differ in more than underscores.',
                    $keys[$property->name],
                    $property->key,
                    $object->className,
                    $property->name,
                ));
            }

            $keys[$property->name] ??= $property->key;
        }

        return $problems;
    }
}
