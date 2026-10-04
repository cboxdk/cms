<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Override;
use stdClass;

/**
 * The JSON form of the test addon's query tally.board: an object without members, as the query of
 * a page takes no input.
 *
 * @implements JsonCodec<TallyBoard>
 */
final readonly class TallyBoardInputCodec implements JsonCodec
{
    #[Override]
    public function encode(object $dto, ClassificationAccess $access): string
    {
        return JsonText::encode(new stdClass);
    }

    #[Override]
    public function decode(string $json, ClassificationAccess $access): TallyBoard
    {
        JsonValues::object(JsonText::decode($json), null, []);

        return new TallyBoard;
    }
}
