<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Rules;

/*
 * Public classes are marked Stable, Experimental or Internal (GUARDRAILS 2.3). Every class,
 * interface, trait and enum in packages/src carries exactly one of them.
 */

arch('stability attributes: every class, interface, trait and enum in packages/src carries exactly one', function (): void {
    $markers = [Stable::class, Experimental::class, Internal::class];
    $types = Codebase::packageTypes();
    $violations = [];

    foreach ($types as $type) {
        $name = $type->fqcn();
        $location = sprintf('%s (%s:%d)', $name, Codebase::relative($type->path), $type->line);

        if (! class_exists($name) && ! interface_exists($name) && ! trait_exists($name) && ! enum_exists($name)) {
            $violations[] = $location.' cannot be autoloaded.';

            continue;
        }

        $found = array_values(array_filter(
            array_map(static fn (ReflectionAttribute $attribute): string => $attribute->getName(), new ReflectionClass($name)->getAttributes()),
            static fn (string $attribute): bool => in_array($attribute, $markers, true),
        ));

        if (count($found) !== 1) {
            $violations[] = sprintf('%s has %d stability attributes: [%s].', $location, count($found), implode(', ', $found));
        }
    }

    expect($types)->not->toBeEmpty();
    Rules::none($violations, 'Mark each type with exactly one of #[Stable], #[Experimental] and #[Internal]:');
});
