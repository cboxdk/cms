<?php

declare(strict_types=1);

namespace Fixture\Entries\Domain;

final class PropertyEntry {}

final class Properties
{
    public array $untyped = [];

    /** @var list<PropertyEntry> */
    public array $typed = [];

    public mixed $anything = null;

    /** @var array<string, mixed> */
    public array $mixedMap = [];

    /**
     * @param array<string, mixed> $promoted
     */
    public function __construct(
        public array $promoted = [],
        public ?PropertyEntry $entry = null,
    ) {}
}

namespace Fixture\Receipts\Adapter;

final class Properties
{
    public array $untyped = [];

    public mixed $anything = null;
}
