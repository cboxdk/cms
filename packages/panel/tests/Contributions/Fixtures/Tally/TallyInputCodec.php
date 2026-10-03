<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Override;
use stdClass;

/**
 * The JSON form of a test addon query, TallyNotes or HeavyTally: an object with the note, which a
 * document without it, or with anything else, does not pass.
 *
 * @implements JsonCodec<TallyNotes|HeavyTally>
 */
final readonly class TallyInputCodec implements JsonCodec
{
    /**
     * @param  class-string<TallyNotes|HeavyTally>  $class
     */
    public function __construct(private string $class) {}

    /**
     * @param  TallyNotes|HeavyTally  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->note = $dto->note;

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): TallyNotes|HeavyTally
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['note']);
        $note = JsonValues::required($object, 'note', null, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at, minLength: 1));

        return new ($this->class)($note);
    }
}
