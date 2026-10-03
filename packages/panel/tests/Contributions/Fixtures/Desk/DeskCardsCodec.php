<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Override;
use stdClass;

/**
 * The JSON form of the test-only props DeskCardsV1, as a generated point codec writes it: the note,
 * and the memo only for a reader whose access allows confidential.
 *
 * @implements JsonCodec<DeskCardsV1>
 */
final readonly class DeskCardsCodec implements JsonCodec
{
    public const string SCHEMA = '{"type":"object","additionalProperties":false,"required":["note"],"properties":{"memo":{"type":"string","x-cms-classification":"confidential"},"note":{"type":"string","minLength":1}}}';

    /**
     * @param  DeskCardsV1  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;

        if ($access->allows(ClassificationAccess::Confidential)) {
            $json->memo = $dto->memo;
        }

        $json->note = $dto->note;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): DeskCardsV1
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['memo', 'note']);

        return new DeskCardsV1(
            JsonValues::required($object, 'note', null, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at, minLength: 1)),
            JsonValues::required($object, 'memo', null, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at)),
        );
    }
}
