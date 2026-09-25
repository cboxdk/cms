<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Category;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Layer;

/*
 * readonly per category (GUARDRAILS 2.2): commands, queries, DTOs, receipts and actions are
 * final readonly classes. Enums and interfaces do not belong in these namespaces.
 */

foreach (Category::cases() as $category) {
    arch("final readonly: classes in {$category->value} namespaces", function () use ($category): void {
        expect(Codebase::classesInCategory($category))->toBeFinal()->toBeReadonly();
    });
}

arch('final readonly: classes in Actions namespaces', function (): void {
    expect(Codebase::classesIn(Layer::Actions))->toBeFinal()->toBeReadonly();
});
