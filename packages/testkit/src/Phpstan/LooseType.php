<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What TypedDeclarationsRule reports in a declared type outside Boundary and Adapter
 * (GUARDRAILS 2.2). Each case has its own error identifier.
 */
#[Internal]
enum LooseType: string
{
    /** An array whose key or value type is mixed: array, mixed[], list<mixed>, array<string, mixed>. */
    case UntypedArray = 'untypedArray';

    /** An array shape or tuple: array{id: int}, list{int, string}. Structured data is a DTO. */
    case ArrayShape = 'arrayShape';

    /** mixed anywhere else: a mixed parameter, iterable<mixed>, Collection<int, mixed>, callable(mixed): void. */
    case Mixed = 'mixed';

    public function identifier(): string
    {
        return 'cboxCms.'.$this->value;
    }

    public function singular(): string
    {
        return match ($this) {
            self::UntypedArray => 'an untyped array',
            self::ArrayShape => 'an array shape',
            self::Mixed => 'mixed',
        };
    }

    public function restriction(): string
    {
        return match ($this) {
            self::UntypedArray => 'untyped arrays are',
            self::ArrayShape => 'array shapes are',
            self::Mixed => 'mixed is',
        }.' only allowed in Boundary and Adapter namespaces (GUARDRAILS 2.2)';
    }

    public function advice(): string
    {
        return match ($this) {
            self::UntypedArray => 'Use list<T> or array<K, V> with key and value types other than mixed',
            self::ArrayShape => 'Use a DTO for structured data',
            self::Mixed => 'Use a precise type',
        };
    }
}
