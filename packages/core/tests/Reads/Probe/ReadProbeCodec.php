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
 * The JSON form of the test-only query probe.read: an object whose only key, rows, is an integer of
 * 1 or more and defaults to 1.
 *
 * @implements JsonCodec<ReadProbe>
 */
final readonly class ReadProbeCodec implements JsonCodec
{
    public const string SCHEMA = '{"type":"object","additionalProperties":false,"properties":{"rows":{"type":"integer","minimum":1,"default":1}}}';

    /**
     * @param  ReadProbe  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->rows = $dto->rows;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): ReadProbe
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['rows']);

        return new ReadProbe(JsonValues::defaulted($object, 'rows', null, static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at, 1), 1));
    }
}
