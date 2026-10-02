<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\TypeScriptEmitter;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Boundary\JsonSchemaContract;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Box;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\FindBoxes;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\UnnamedFind;
use LogicException;

/*
 * The schemas of a query's contract version (GUARDRAILS 2.1, 2.2, PRD 6.2, 8.8): the reader binds
 * the query's document to the query, takes the name from its #[Query] and refuses a version the
 * attribute does not give or a binding that names no codec of the result; it binds the result's
 * document to a class that implements Result. The query's codec carries the query's schema and
 * builds its QueryCodec with the result's codec, which carries the result's schema; the result
 * lists every query's codec in KernelQueryCodecs and refuses a query whose result has no codec; and
 * the TypeScript of both is a module each.
 */

/**
 * @return array{CodecContract, CodecContract}
 */
function queryContracts(): array
{
    return [
        JsonSchemaContract::read(QuerySchemas::query(), QuerySchemas::queryBinding(), Experimental::class),
        JsonSchemaContract::read(QuerySchemas::result(), QuerySchemas::resultBinding(), Experimental::class),
    ];
}

/**
 * The message of the problem the reader gives for the schema and binding.
 */
function queryProblem(string $json, SchemaBinding $binding): string
{
    try {
        JsonSchemaContract::read($json, $binding, Experimental::class);
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::SchemaInvalid]);

        return $failed->problems[0]->message;
    }

    throw new LogicException('The schema was read.');
}

/**
 * The file of a result that is named $path.
 *
 * @param  list<GeneratedFile>  $files
 */
function generatedFile(array $files, string $path): GeneratedFile
{
    return array_first(array_filter($files, static fn (GeneratedFile $file): bool => $file->path === $path))
        ?? throw new LogicException($path.' was not generated.');
}

it('reads a query schema and its result schema into the codec contracts of the query and its result', function (): void {
    [$query, $result] = queryContracts();

    expect($query->query?->name)->toBe('probe.find_boxes')
        ->and($query->query?->resultCodec)->toBe(QuerySchemas::RESULT_CODEC)
        ->and(json_decode((string) $query->query?->schema, true, 64, JSON_THROW_ON_ERROR))->toBe(json_decode(QuerySchemas::query(), true, 64, JSON_THROW_ON_ERROR))
        ->and($query->command)->toBeNull()
        ->and($query->result)->toBeNull()
        ->and($query->root->class)->toBe(FindBoxes::class)
        ->and($result->result?->query)->toBe('probe.find_boxes')
        ->and(json_decode((string) $result->result?->schema, true, 64, JSON_THROW_ON_ERROR))->toBe(json_decode(QuerySchemas::result(), true, 64, JSON_THROW_ON_ERROR))
        ->and($result->query)->toBeNull()
        ->and($result->root->objects()[1]->class ?? null)->toBe(Box::class);
});

it('emits the query\'s codec with its schema and QueryCodec, the result\'s codec with its schema, and the list of the queries\' codecs', function (): void {
    $location = new PhpLocation('Generated', 'Cbox\Cms\Probe\Generated');
    $files = ProtocolSchemas::result(queryContracts(), $location)->files;
    $query = generatedFile($files, 'Generated/FindBoxesCodecV1.php')->contents;
    $result = generatedFile($files, 'Generated/FoundBoxesCodecV1.php')->contents;
    $list = generatedFile($files, 'Generated/'.ProtocolSchemas::QUERY_CODECS.'.php')->contents;

    expect(array_map(static fn (GeneratedFile $file): string => $file->path, $files))->toBe([
        'Generated/FindBoxesCodecV1.php',
        'Generated/FoundBoxesCodecV1.php',
        'Generated/KernelQueryCodecs.php',
    ]);

    foreach ([
        "public const string QUERY = 'probe.find_boxes';",
        'public const string SCHEMA = <<<\'SCHEMA\'',
        '"title": "probe.find_boxes, contract version 1",',
        'public static function queryCodec(): QueryCodec',
        '            new FoundBoxesCodecV1,',
        '            new JsonSchema(FoundBoxesCodecV1::SCHEMA),',
        'use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;',
        'final readonly class FindBoxesCodecV1 implements JsonCodec',
    ] as $line) {
        expect($query)->toContain($line);
    }

    foreach ([
        'public const string SCHEMA = <<<\'SCHEMA\'',
        '"title": "probe.find_boxes result, contract version 1",',
        'The JSON Schema of the result of probe.find_boxes, which this codec writes and reads',
        'final readonly class FoundBoxesCodecV1 implements JsonCodec',
    ] as $line) {
        expect($result)->toContain($line);
    }

    expect(str_contains($query, 'CommandEncoder'))->toBeFalse()
        ->and(str_contains($result, 'queryCodec'))->toBeFalse()
        ->and($list)->toContain('final readonly class KernelQueryCodecs', '     * @return list<QueryCodec>', '            FindBoxesCodecV1::queryCodec(),');
});

it('emits a TypeScript module with a validator for the query and one for its result', function (): void {
    [$query, $result] = queryContracts();
    $queryModule = TypeScriptEmitter::emit($query, 'protocol/FindBoxesV1.ts', '../validation', ['The query.'])->contents;
    $resultModule = TypeScriptEmitter::emit($result, 'protocol/FoundBoxesV1.ts', '../validation', ['Its result.'])->contents;

    expect($queryModule)->toContain('export interface FindBoxesV1 {')
        ->toContain('  label: string;')
        ->toContain('export function validateFindBoxesV1(')
        ->and($resultModule)->toContain('export interface FoundBoxesV1 {')
        ->toContain('  first: BoxV1 | null;')
        ->toContain('export interface BoxV1 {')
        ->toContain('export function validateFoundBoxesV1(');
});

it('refuses a query schema whose query is not the bound class, has no #[Query], is another version or names no codec of its result', function (): void {
    expect(queryProblem(QuerySchemas::query(), new SchemaBinding('probe.find_boxes.v1.json', QuerySchemas::QUERY_CODEC, 1, ['#' => FindBoxes::class], query: Box::class, resultCodec: QuerySchemas::RESULT_CODEC)))
        ->toContain('is the schema of the query '.Box::class.', which is not a class or not the class the document is bound to')
        ->and(queryProblem(QuerySchemas::query(), QuerySchemas::queryBinding(UnnamedFind::class)))
        ->toContain('is the schema of the query '.UnnamedFind::class.', which is not a class with #[Query]')
        ->and(queryProblem(QuerySchemas::query(), QuerySchemas::queryBinding(version: 2)))
        ->toContain('is version 2 of the query '.FindBoxes::class.', but its #[Query] gives version 1')
        ->and(queryProblem(QuerySchemas::query(), QuerySchemas::queryBinding(resultCodec: null)))
        ->toContain('names no codec of its result')
        ->and(queryProblem(QuerySchemas::query(), QuerySchemas::queryBinding(resultCodec: 'found boxes')))
        ->toContain('names no codec of its result');
});

it('refuses a result schema whose document is not bound to a Result', function (): void {
    expect(queryProblem(QuerySchemas::query(), new SchemaBinding('probe.find_boxes.result.v1.json', QuerySchemas::RESULT_CODEC, 1, ['#' => FindBoxes::class], resultOf: FindBoxes::class)))
        ->toContain('is the schema of the result of the query '.FindBoxes::class.', and its document is not bound to a class that implements');
});

it('refuses a query whose result has no codec among the contracts', function (string $resultCodec): void {
    [$query] = queryContracts();
    $result = JsonSchemaContract::read(QuerySchemas::result(), QuerySchemas::resultBinding(codecClass: $resultCodec), Experimental::class);

    try {
        ProtocolSchemas::result($resultCodec === QuerySchemas::RESULT_CODEC ? [$query] : [$query, $result], new PhpLocation('Generated', 'Cbox\Cms\Probe\Generated'));
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
            ->and($failed->problems[0]->message)->toBe('The query probe.find_boxes version 1 names the codec FoundBoxesCodecV1 of its result, which is not the codec of a schema of its result at that version.');

        return;
    }

    throw new LogicException('A query without the codec of its result was accepted.');
})->with(['no result at all' => [QuerySchemas::RESULT_CODEC], 'a result under another codec' => ['OtherBoxesCodecV1']]);
