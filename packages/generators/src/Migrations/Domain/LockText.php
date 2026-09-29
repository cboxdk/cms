<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;

/**
 * The text of a schema lock file (PRD 11.6): JSON with every key present, the keys of each object
 * in sorted order, the columns in the lock's order (by step, then by name), pretty-printed with
 * four spaces, unescaped slashes and unescaped Unicode, ending with one newline. The same lock
 * always gives the same bytes, and the Boundary's TypeTableLockJson reads a file back only when it
 * is exactly this text.
 */
#[Internal]
final readonly class LockText
{
    public static function encode(TypeTableLock $lock): string
    {
        $columns = [];

        foreach ($lock->columns as $column) {
            $columns[] = [
                'checks' => $column->checks,
                'indexed' => $column->indexed,
                'name' => $column->name,
                'not_null' => $column->notNull,
                'step' => $column->step,
                'type' => $column->type,
            ];
        }

        $document = [
            'about' => sprintf(
                'The schema lock of the type table %s: the columns its migrations %s_<step> build, in %d %s. cms:generate writes the lock and the migrations from it; do not edit either.',
                $lock->table,
                $lock->table,
                $lock->steps,
                $lock->steps === 1 ? 'step' : 'steps',
            ),
            'columns' => $columns,
            'localization' => $lock->localization->value,
            'lock' => TypeTableLock::FORMAT,
            'stages' => $lock->stages->value,
            'steps' => $lock->steps,
            'table' => $lock->table,
            'type' => $lock->type->value,
            'type_id' => $lock->typeId->toString(),
        ];

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
