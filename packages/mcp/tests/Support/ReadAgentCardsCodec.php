<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Support;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Mcp\Tests\Fixtures\Surface\ReadAgentCards;
use Override;
use stdClass;

/**
 * The JSON form of the test-only query probe.agent_cards, as the generated codec of a query reads
 * and writes one (GUARDRAILS 2.2): an object whose only key, rows, is an integer of 1 or more and
 * defaults to 1.
 *
 * @implements JsonCodec<ReadAgentCards>
 */
final readonly class ReadAgentCardsCodec implements JsonCodec
{
    public const string SCHEMA = <<<'JSON'
        {
          "$schema": "https://json-schema.org/draft/2020-12/schema",
          "title": "probe.agent_cards, version 1",
          "description": "The cards of the probe library an agent may read.",
          "type": "object",
          "additionalProperties": false,
          "properties": {
            "rows": {"description": "How many cards to read.", "type": "integer", "minimum": 1, "default": 1}
          }
        }
        JSON;

    /**
     * @param  ReadAgentCards  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->rows = $dto->rows;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ReadAgentCards
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['rows']);

        return new ReadAgentCards(JsonValues::defaulted($object, 'rows', null, static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at, 1), 1));
    }
}
