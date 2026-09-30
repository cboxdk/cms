<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Boundary\FieldValuesInput;
use Cbox\Cms\Core\Pipeline\Domain\CommandContentHasher;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use InvalidArgumentException;
use Override;

/**
 * The content hash of seed.entries (PRD 6.1), the one command of the seeder's pipeline: the SHA-256
 * of the command's name, its version and the chunk's canonical JSON, each entry as its id, type,
 * home node, release flag and fields in their input form (FieldValuesInput, handles sorted, a
 * date-time in UTC with microseconds). The same chunk gives the same hash in every process, so a
 * chunk that runs again under its derived key replays, and a chunk with other content under that
 * key is idempotency_conflict.
 */
#[Internal]
final readonly class SeedContentHasher implements CommandContentHasher
{
    /**
     * @throws InvalidArgumentException for another command than seed.entries
     */
    #[Override]
    public function hash(CommandName $command, int $version, Command $input): ContentHash
    {
        if (! $input instanceof SeedEntries) {
            throw new InvalidArgumentException(sprintf('The seeder hashes only seed.entries, not %s.', $input::class));
        }

        return ContentHash::of($command->value."\n".$version."\n".self::canonical($input));
    }

    /**
     * The chunk as canonical JSON.
     */
    public static function canonical(SeedEntries $command): string
    {
        return json_encode(
            array_map(static fn (SeededEntry $entry): array => [
                'entry' => $entry->entry->toString(),
                'fields' => FieldValuesInput::of($entry->fields),
                'home' => $entry->home->toString(),
                'release' => $entry->release,
                'type' => $entry->type->toString(),
            ], $command->entries),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
