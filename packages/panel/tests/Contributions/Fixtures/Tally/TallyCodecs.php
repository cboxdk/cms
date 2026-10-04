<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Override;
use stdClass;

/**
 * The QueryCodecs of the test addon's queries, as a generated query codec gives them: each query
 * read from an object with its note, tally.board from one without members, and its TallyCount written with the count, the summary for a
 * reader whose access allows internal and the owner for one whose access allows confidential.
 *
 * @implements JsonCodec<TallyCount>
 */
final readonly class TallyCodecs implements JsonCodec
{
    public const string INPUT = '{"type":"object","additionalProperties":false,"required":["note"],"properties":{"note":{"type":"string","minLength":1}}}';

    /** The input of tally.board: none, as the query of a page takes. */
    public const string NO_INPUT = '{"type":"object","additionalProperties":false,"properties":{}}';

    public const string RESULT = '{"type":"object","additionalProperties":false,"required":["count"],"properties":{"count":{"type":"integer","minimum":0},"owner":{"type":"string","x-cms-classification":"confidential"},"summary":{"type":"string","x-cms-classification":"internal"}}}';

    /**
     * @return list<QueryCodec>
     */
    public static function all(): array
    {
        return [
            new QueryCodec(new CommandName('tally.board'), 1, new TallyBoardInputCodec, new JsonSchema(self::NO_INPUT), new self, new JsonSchema(self::RESULT)),
            new QueryCodec(new CommandName('tally.heavy'), 1, new TallyInputCodec(HeavyTally::class), new JsonSchema(self::INPUT), new self, new JsonSchema(self::RESULT)),
            new QueryCodec(new CommandName('tally.notes'), 1, new TallyInputCodec(TallyNotes::class), new JsonSchema(self::INPUT), new self, new JsonSchema(self::RESULT)),
        ];
    }

    /**
     * @param  TallyCount  $dto
     */
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        $json = new stdClass;
        $json->count = $dto->count;

        if ($access->allows(ClassificationAccess::Confidential)) {
            $json->owner = $dto->owner;
        }

        if ($access->allows(ClassificationAccess::Internal)) {
            $json->summary = $dto->summary;
        }

        return JsonText::encode($json);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): TallyCount
    {
        $object = JsonValues::object(JsonText::decode($json), null, ['count', 'owner', 'summary']);

        return new TallyCount(
            JsonValues::required($object, 'count', null, static fn (mixed $value, FieldPath $at): int => JsonValues::integer($value, $at, 0)),
            JsonValues::required($object, 'summary', null, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at)),
            JsonValues::required($object, 'owner', null, static fn (mixed $value, FieldPath $at): string => JsonValues::text($value, $at)),
        );
    }
}
