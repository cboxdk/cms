<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Codec\Domain\PhpCodecEmitter;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Protocol\Boundary\JsonSchemaContract;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Step;
use Cbox\Cms\Generators\Tests\Codec\Fixtures\Tone;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Box;
use Cbox\Cms\Generators\Tests\Protocol\Fixtures\Parcel;
use Closure;

/**
 * A probe schema for JsonSchemaContract with every keyword it reads, bound to the classes in
 * Fixtures, and the codec PhpCodecEmitter writes for it, committed as Fixtures/ParcelCodecV1.php.
 */
final class ParcelSchema
{
    public const string FILE = 'parcel.v1.json';

    /**
     * The schema as PHP values, changed by $change before it is encoded.
     *
     * @param  ?Closure(array<string, mixed>): array<string, mixed>  $change
     */
    public static function json(?Closure $change = null): string
    {
        $document = self::document();

        return (string) json_encode($change instanceof Closure ? $change($document) : $document, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'Parcel, contract version 1',
            'description' => 'A probe document.',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'label', 'owner', 'sent_at', 'step'],
            'properties' => [
                'box' => ['description' => 'A box.', '$ref' => '#/$defs/box', 'default' => (object) []],
                'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9, 'default' => 3],
                'fragile' => ['type' => 'boolean', 'default' => false],
                'id' => ['type' => ['string', 'null'], 'pattern' => '^[0-9a-f-]{36}$'],
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
                'note' => ['type' => ['null', 'string'], 'maxLength' => 200, 'default' => null],
                'owner' => ['anyOf' => [['type' => 'null'], ['$ref' => '#/$defs/box']]],
                'sent_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'step' => ['enum' => [1, 2]],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 63], 'minItems' => 0, 'maxItems' => 4, 'default' => []],
                'tone' => ['type' => 'string', 'pattern' => '^[a-z]+$', 'maxLength' => 8, 'default' => 'warm'],
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
        ];
    }

    public static function binding(): SchemaBinding
    {
        return new SchemaBinding(
            schema: self::FILE,
            codecClass: 'ParcelCodecV1',
            version: 1,
            objects: ['#' => Parcel::class, '#/$defs/box' => Box::class],
            values: [
                '#/properties/id' => ValueBinding::id(ChangesetId::class),
                '#/properties/step' => ValueBinding::enum(Step::class),
                '#/properties/tags/items' => ValueBinding::value(ProjectionName::class),
                '#/properties/tone' => ValueBinding::enum(Tone::class),
            ],
        );
    }

    /**
     * @param  ?Closure(array<string, mixed>): array<string, mixed>  $change
     */
    public static function contract(?Closure $change = null, ?SchemaBinding $binding = null): CodecContract
    {
        return JsonSchemaContract::read(self::json($change), $binding ?? self::binding(), Experimental::class);
    }

    public static function codec(): GeneratedFile
    {
        $location = new PhpLocation('Fixtures', 'Cbox\Cms\Generators\Tests\Protocol\Fixtures');

        return PhpCodecEmitter::emit(self::contract(), $location, $location);
    }
}
