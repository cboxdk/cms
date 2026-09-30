<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes\Boundary;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Examples\Unit\Build\Notes\FoundNote;
use Override;
use stdClass;

/**
 * The JSON form of the result of note.find: an object that says whether a note has the title.
 *
 * @implements JsonCodec<FoundNote>
 */
final readonly class FoundNoteCodec implements JsonCodec
{
    public const string SCHEMA = '{"type":"object","additionalProperties":false,"required":["found"],"properties":{"found":{"type":"boolean"}}}';

    /**
     * @param  FoundNote  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->found = $dto->found;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): FoundNote
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['found']);

        return new FoundNote(JsonValues::required($object, 'found', null, static fn (mixed $value, FieldPath $at): bool => JsonValues::boolean($value, $at)));
    }
}
