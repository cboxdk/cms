<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol;

use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Box;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\FindBoxes;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\FoundBoxes;
use Closure;

/**
 * The probe schemas of a query's contract version, probe.find_boxes version 1: the query's document
 * and its result's, bound to the classes in Fixtures as ProtocolSchemas binds a kernel query.
 */
final class QuerySchemas
{
    public const string QUERY_CODEC = 'FindBoxesCodecV1';

    public const string RESULT_CODEC = 'FoundBoxesCodecV1';

    /**
     * The query's schema, changed by $change before it is encoded.
     *
     * @param  ?Closure(array<string, mixed>): array<string, mixed>  $change
     */
    public static function query(?Closure $change = null): string
    {
        $document = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'probe.find_boxes, contract version 1',
            'description' => 'The boxes with a label.',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['label'],
            'properties' => [
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
            ],
        ];

        return self::encode($change instanceof Closure ? $change($document) : $document);
    }

    public static function result(): string
    {
        return self::encode([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'probe.find_boxes result, contract version 1',
            'description' => 'The first box with the label, and how many there are.',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['count', 'first'],
            'properties' => [
                'count' => ['type' => 'integer', 'minimum' => 0],
                'first' => ['anyOf' => [['$ref' => '#/$defs/box'], ['type' => 'null']]],
            ],
            '$defs' => [
                'box' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'size' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  class-string  $query
     * @param  positive-int  $version
     */
    public static function queryBinding(string $query = FindBoxes::class, ?string $resultCodec = self::RESULT_CODEC, int $version = 1): SchemaBinding
    {
        return new SchemaBinding(
            schema: 'probe.find_boxes.v'.$version.'.json',
            codecClass: self::QUERY_CODEC,
            version: $version,
            objects: ['#' => $query],
            query: $query,
            resultCodec: $resultCodec,
        );
    }

    /**
     * @param  class-string  $result  the class the result's document is bound to
     */
    public static function resultBinding(string $result = FoundBoxes::class, string $codecClass = self::RESULT_CODEC): SchemaBinding
    {
        return new SchemaBinding(
            schema: 'probe.find_boxes.result.v1.json',
            codecClass: $codecClass,
            version: 1,
            objects: ['#' => $result, '#/$defs/box' => Box::class],
            resultOf: FindBoxes::class,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function encode(array $document): string
    {
        return (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
