<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Operations\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A name, a key or a chunk plan of an operation is not valid, or a stored operation cannot be
 * read. Nothing was started or advanced.
 */
#[Experimental]
final class InvalidOperation extends InvalidArgumentException
{
    public static function kind(string $kind): self
    {
        return new self(sprintf(
            'The operation kind "%s" is not valid. Use lower-case segments of letters, digits and underscores separated by dots, such as "entries.rebuild", at most %d characters.',
            $kind,
            OperationKind::MAX_LENGTH,
        ));
    }

    public static function key(string $key): self
    {
        return new self(sprintf(
            'The operation key "%s" is not valid. Use 1 to %d visible ASCII characters.',
            $key,
            OperationKey::MAX_LENGTH,
        ));
    }

    public static function chunk(string $chunk): self
    {
        return new self(sprintf(
            'The chunk name "%s" is not valid. Use 1 to %d visible ASCII characters.',
            $chunk,
            ChunkName::MAX_LENGTH,
        ));
    }

    public static function duplicateChunk(ChunkName $chunk): self
    {
        return new self(sprintf('The chunk plan names the chunk "%s" twice. Every chunk of an operation has its own name.', $chunk->value));
    }

    public static function id(string $value): self
    {
        return new self(sprintf('The operation id "%s" is not valid. It is 1 to 255 visible ASCII characters.', $value));
    }

    public static function unreadable(string $operation, string $reason): self
    {
        return new self(sprintf('The stored operation "%s" cannot be read: %s.', $operation, $reason));
    }
}
