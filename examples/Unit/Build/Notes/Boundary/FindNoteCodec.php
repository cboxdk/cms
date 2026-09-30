<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes\Boundary;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Examples\Unit\Build\Notes\FindNote;
use Override;
use stdClass;

/**
 * The JSON form of note.find, version 1: an object with the title, 1 to 200 characters. The REST
 * surface reads the query with it, and cms:build describes the query with SCHEMA.
 *
 * @implements JsonCodec<FindNote>
 */
final readonly class FindNoteCodec implements JsonCodec
{
    public const string SCHEMA = '{"type":"object","additionalProperties":false,"required":["title"],"properties":{"title":{"type":"string","minLength":1,"maxLength":200}}}';

    /**
     * @param  FindNote  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->title = $dto->title;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): FindNote
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['title']);

        return new FindNote(JsonValues::required($object, 'title', null, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at, 1, 200)));
    }
}
