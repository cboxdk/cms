<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Override;
use stdClass;

/**
 * The JSON form of the test-only result ProbeCount: an object with the number of cards.
 *
 * @implements JsonCodec<ProbeCount>
 */
final readonly class ProbeCountCodec implements JsonCodec
{
    public const string SCHEMA = '{"type":"object","additionalProperties":false,"required":["cards"],"properties":{"cards":{"type":"integer","minimum":0}}}';

    /**
     * @param  ProbeCount  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->cards = $dto->cards;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ProbeCount
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['cards']);

        return new ProbeCount(JsonValues::required($object, 'cards', null, static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at, 0)));
    }
}
