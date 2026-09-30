<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\CommandContentHasher;
use Cbox\Cms\Core\Pipeline\Domain\CommandEncoder;
use Cbox\Cms\Core\Pipeline\Domain\UnknownCommand;
use Override;

/**
 * The content hash of a command (PRD 6.1) over its canonical form, which the generated codec of
 * its name and version writes (GUARDRAILS 2.2): the SHA-256 of the name, the version and the
 * command's canonical JSON, written at the highest classification access, so every field counts.
 * The codec writes the keys sorted and without whitespace, so equal input gives equal hashes in
 * every process and on every node, and the same input under another version is other content. A
 * codec that is not a CommandEncoder, as every generated command codec is, cannot hash a command.
 */
#[Internal]
final readonly class CodecCommandContentHasher implements CommandContentHasher
{
    public function __construct(private CommandCodecs $codecs) {}

    /**
     * @throws UnknownCommand when no codec, or no CommandEncoder, reads that version of the command
     * @throws EncodingFailed when the command holds a value that has no form in its contract
     */
    #[Override]
    public function hash(CommandName $command, int $version, Command $input): ContentHash
    {
        $codec = $this->codecs->for($command, $version)->codec;

        if (! $codec instanceof CommandEncoder) {
            throw UnknownCommand::noCodec($command->value, $version);
        }

        return ContentHash::of($command->value."\n".$version."\n".$codec->encodeCommand($input));
    }
}
