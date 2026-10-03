<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use InvalidArgumentException;

/**
 * The CommandCodec of each version of each command an exposed surface reads (GUARDRAILS 2.1, 2.2):
 * the codecs the core's service provider finds under the container tag TAG, one per name and
 * version. A command task adds its command's codec by tagging it; an exposed surface cannot read a
 * command without one.
 */
#[Experimental]
final readonly class CommandCodecs
{
    /** The container tag the codecs are registered under. */
    public const string TAG = 'cbox-cms.command-codecs';

    /** @var array<string, CommandCodec> by name and version */
    private array $codecs;

    /**
     * @throws InvalidArgumentException when two codecs read the same version of a command
     */
    public function __construct(CommandCodec ...$codecs)
    {
        $byKey = [];

        foreach ($codecs as $codec) {
            $key = $this->key($codec->command, $codec->version);

            if (isset($byKey[$key])) {
                throw new InvalidArgumentException(sprintf('Two codecs are registered for version %d of the command %s.', $codec->version, $codec->command->value));
            }

            $byKey[$key] = $codec;
        }

        $this->codecs = $byKey;
    }

    /**
     * @throws UnknownCommand when no codec reads that version of the command
     */
    public function for(CommandName $command, int $version): CommandCodec
    {
        return $this->find($command, $version) ?? throw UnknownCommand::noCodec($command->value, $version);
    }

    /**
     * The codec of that version of the command, or null when none is registered.
     */
    public function find(CommandName $command, int $version): ?CommandCodec
    {
        return $this->codecs[$this->key($command, $version)] ?? null;
    }

    /**
     * Every registered codec, sorted by command name, then version.
     *
     * @return list<CommandCodec>
     */
    public function all(): array
    {
        $codecs = $this->codecs;
        ksort($codecs, SORT_STRING);

        return array_values($codecs);
    }

    private function key(CommandName $command, int $version): string
    {
        return $command->value.'@'.$version;
    }
}
