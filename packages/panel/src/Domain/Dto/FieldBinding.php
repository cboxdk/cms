<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The value class a command binds one member of its document to (command-form.v1.json,
 * `#/$defs/binding`): the member's path in the document, such as `node`, and the class, such as
 * Cbox\Cms\Contracts\Ids\NodeId, which is the key a replacement of the member's input names at
 * command.form.field@1. A member bound to no class, such as a plain string, an integer or the
 * fields of a revision, has no binding.
 */
#[Internal]
final readonly class FieldBinding
{
    public function __construct(
        public string $path,
        public string $class,
    ) {}
}
