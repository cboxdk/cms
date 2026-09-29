<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a ValidateHook answers: the errors it adds, in order. None means the hook finds nothing
 * wrong.
 */
#[Experimental]
final readonly class HookErrors
{
    /** @var list<HookError> */
    public array $errors;

    public function __construct(HookError ...$errors)
    {
        $this->errors = array_values($errors);
    }

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }
}
