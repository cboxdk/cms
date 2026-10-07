<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\Dto\FieldBinding;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The value classes a command binds the members of its document to (PRD 13.4, section 3.6 of the
 * panel extension architecture), read from the command's PHP form: each promoted parameter of its
 * constructor whose type is one class, such as NodeId, is bound to the member whose key is the
 * parameter's name in snake_case, as the generated codecs name them, when the command's JSON
 * Schema has such a property (SchemaProperties). A member of a plain type, of a union type or of
 * no property in the schema has no binding. The classes key the replacements of the members'
 * inputs at command.form.field@1: the core's pickers of NodeId, ActorId and RoleId, and an addon's
 * of its own value classes.
 */
#[Internal]
final readonly class CommandBindings
{
    /**
     * The bindings of the command's class to the schema's properties, sorted by path; none for a
     * class that is not loaded.
     *
     * @param  string  $command  the command's class, as the registry names it
     * @param  list<string>  $properties  the names of the schema's properties
     * @return list<FieldBinding>
     */
    public static function of(string $command, array $properties): array
    {
        if (! class_exists($command)) {
            return [];
        }

        $constructor = new ReflectionClass($command)->getConstructor();
        $bindings = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $key = Str::snake($parameter->getName());

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! in_array($key, $properties, true)) {
                continue;
            }

            $bindings[$key] = new FieldBinding($key, ltrim($type->getName(), '\\'));
        }

        ksort($bindings, SORT_STRING);

        return array_values($bindings);
    }
}
