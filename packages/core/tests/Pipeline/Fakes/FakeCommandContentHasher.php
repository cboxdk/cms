<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\CommandContentHasher;
use Override;

/**
 * Hashes the command's name, version and PHP serialisation, which is canonical enough for the
 * probe command's value objects in one test process, and counts the hashes it took.
 */
final class FakeCommandContentHasher implements CommandContentHasher
{
    public int $hashes = 0;

    #[Override]
    public function hash(CommandName $command, int $version, Command $input): ContentHash
    {
        $this->hashes++;

        return ContentHash::of($command->value."\n".$version."\n".serialize($input));
    }
}
