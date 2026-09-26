<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Where a definition was read from: the blueprint file, relative to the base of its schema root
 * such as `schema/blog/article.yaml`, and the JSON pointer of the definition in the file, such as
 * `/fields/2` for a field or the empty string for the whole document (RFC 6901). Problems found
 * later, such as a duplicate handle, name this place.
 */
#[Internal]
final readonly class SourceLocation
{
    public function __construct(
        public string $file,
        public string $pointer,
    ) {}

    /**
     * The place of a value below this one, such as `/fields/2/options/0`.
     */
    public function below(string|int ...$segments): self
    {
        $pointer = $this->pointer;

        foreach ($segments as $segment) {
            $pointer .= '/'.str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
        }

        return new self($this->file, $pointer);
    }

    /**
     * The file and the pointer as a problem names them: `schema/article.yaml, /fields/2`. The whole
     * document is `/`.
     */
    public function describe(): string
    {
        return sprintf('%s, %s', $this->file, $this->pointer === '' ? '/' : $this->pointer);
    }
}
