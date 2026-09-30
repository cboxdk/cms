<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest\Support;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Http\Tests\Rest\Fixtures\Surface\ReadCards;
use Override;
use stdClass;

/**
 * The JSON form of the test-only query probe.cards, as a query's codec reads it: an object whose
 * only key, rows, is an integer from 1 to 50 and defaults to 1.
 *
 * @implements JsonCodec<ReadCards>
 */
final readonly class ReadCardsCodec implements JsonCodec
{
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "probe.cards, version 1",
          "type": "object",
          "additionalProperties": false,
          "properties": {
            "rows": {"type": "integer", "minimum": 1, "maximum": 50, "default": 1}
          }
        }
        JSON;

    /**
     * @param  ReadCards  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->rows = $dto->rows;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ReadCards
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['rows']);

        return new ReadCards(JsonValues::defaulted($object, 'rows', null, static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at, 1, 50), 1));
    }
}
