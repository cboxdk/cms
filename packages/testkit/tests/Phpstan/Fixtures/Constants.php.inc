<?php

declare(strict_types=1);

namespace Fixture\Entries\Domain;

final class ConstantEntry {}

final class Constants
{
    public const array UNTYPED = ['a', 'b'];

    /** @var list<string> */
    public const array TYPED = ['a', 'b'];

    /** @var array<string, mixed> */
    public const array MIXED_MAP = ['a' => 1];

    /** @var array{a: int} */
    public const array SHAPE = ['a' => 1];

    public const int COUNT = 1;

    public const WITHOUT_TYPE = ['a', 'b'];

    public const array FIRST = [], SECOND = [];

    /** @var array<string, ConstantEntry> */
    public const array ENTRIES = [];
}

interface ConstantsInterface
{
    public const array UNTYPED = [];
}

enum ConstantsEnum: string
{
    case One = 'one';

    public const array UNTYPED = [];

    /** @var list<self> */
    public const array TYPED = [self::One];
}

namespace Fixture\Receipts\Adapter;

final class Constants
{
    public const array UNTYPED = ['a', 'b'];

    /** @var array<string, mixed> */
    public const array MIXED_MAP = ['a' => 1];
}

namespace Fixture\Receipts\Tests;

final class Constants
{
    public const array UNTYPED = ['a', 'b'];
}
