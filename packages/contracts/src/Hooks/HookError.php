<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;

/**
 * One error a ValidateHook adds (PRD 6.2 phase 5): what is wrong in plain language, and the field
 * it is about, or no field when it is about the command as a whole. The kernel answers it as
 * validation_hook_failed with the field's path below the command's fields.
 */
#[Experimental]
final readonly class HookError
{
    /**
     * @throws InvalidHookResult when the message is empty
     */
    public function __construct(
        public string $message,
        public ?FieldHandle $handle = null,
        public ?FieldNamespace $namespace = null,
    ) {
        if (trim($message) === '') {
            throw InvalidHookResult::emptyMessage();
        }
    }

    /**
     * An error about a field of the type's owner, or of an extender under its namespace.
     */
    public static function onField(FieldHandle $handle, string $message, ?FieldNamespace $namespace = null): self
    {
        return new self($message, $handle, $namespace);
    }

    /**
     * An error about the command as a whole.
     */
    public static function onCommand(string $message): self
    {
        return new self($message);
    }
}
