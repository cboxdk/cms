<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A blueprint file below a schema root, as cms:schema:editor edits it.
 */
#[Internal]
final readonly class SchemaFile
{
    /**
     * @param  string  $path  the absolute path the file was found at
     * @param  string  $file  the name problems and reports give it: the root's directory and the file's path below it, such as `schema/article.yaml`
     * @param  string  $directory  the absolute, canonical directory of the file, which its editor line's relative path starts from
     */
    public function __construct(
        public string $path,
        public string $file,
        public string $directory,
    ) {}
}
