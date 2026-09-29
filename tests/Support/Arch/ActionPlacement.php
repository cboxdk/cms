<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use ReflectionClass;

/**
 * Where actions live (GUARDRAILS 2.1, 2.5, "Hvor ting bor" in CLAUDE.md): a class that implements
 * WriteAction or QueryAction is in an Actions namespace, so the rules of that layer hold for it:
 * final readonly, only the domain, the contracts and other actions, and no DB facade or
 * connection. An action's resolve() and plan() then cannot reach a connection to write with, and
 * the pipeline hands them none.
 */
final readonly class ActionPlacement
{
    /**
     * Each action among the types that is outside an Actions namespace, as
     * "<class> (<file>:<line>) ...".
     *
     * @param  list<DeclaredType>  $types
     * @return list<string>
     */
    public static function violations(array $types): array
    {
        $violations = [];

        foreach ($types as $type) {
            $name = $type->fqcn();

            if (! class_exists($name)) {
                continue;
            }

            $class = new ReflectionClass($name);

            if (! $class->implementsInterface(WriteAction::class) && ! $class->implementsInterface(QueryAction::class)) {
                continue;
            }

            if ($type->layer() !== Layer::Actions) {
                $violations[] = sprintf(
                    '%s (%s:%d) implements %s outside an Actions namespace.',
                    $name,
                    Codebase::relative($type->path),
                    $type->line,
                    $class->implementsInterface(WriteAction::class) ? 'WriteAction' : 'QueryAction',
                );
            }
        }

        return $violations;
    }
}
