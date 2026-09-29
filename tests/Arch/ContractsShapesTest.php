<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\Rules;

/*
 * The contracts module holds values, markers and ports (GUARDRAILS 2.1, 2.2, 2.3): every type in
 * Cbox\Cms\Contracts is a final readonly class, an interface or an enum. An exception is a final
 * class, because PHP's exceptions cannot be readonly. No trait and no abstract class, so nothing
 * there carries mutable state or inherits behaviour. PHPStan's cboxCms.mixed, cboxCms.untypedArray
 * and cboxCms.arrayShape rules keep mixed and untyped arrays out, since the module has no Boundary
 * or Adapter.
 */

arch('contracts: every type is a final readonly class, an interface, an enum or a final exception', function (): void {
    $contracts = Codebase::root().'/packages/contracts/src/';
    $types = array_values(array_filter(
        Codebase::packageTypes(),
        static fn (DeclaredType $type): bool => str_starts_with($type->path, $contracts),
    ));
    $violations = [];

    foreach ($types as $type) {
        $name = $type->fqcn();
        $where = sprintf('%s (%s:%d)', $name, Codebase::relative($type->path), $type->line);

        if (interface_exists($name) || enum_exists($name)) {
            continue;
        }

        if (! class_exists($name)) {
            $violations[] = $where.' is a trait or cannot be autoloaded; the contracts module has neither.';

            continue;
        }

        $class = new ReflectionClass($name);

        if (! $class->isFinal()) {
            $violations[] = $where.' is not final.';
        }

        if (! $class->isReadOnly() && ! $class->implementsInterface(Throwable::class)) {
            $violations[] = $where.' is not readonly; only an exception may be a class that is not.';
        }
    }

    expect(count($types))->toBeGreaterThan(100);

    Rules::none($violations, 'Every type in Cbox\Cms\Contracts is a final readonly class, an interface, an enum or a final exception (GUARDRAILS 2.2):');
});
