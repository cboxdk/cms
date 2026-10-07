<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\TypeExpression;

/**
 * Lays out the TypeScript of cms:panel:types as Prettier prints it with the repository's settings
 * (print width 100, two spaces, single quotes, trailing commas), so the generated module passes
 * Prettier unchanged: a line that fits stays on one line; a union that does not moves to the next
 * line, and then breaks into one member per line; a generic that does not breaks its arguments,
 * one per line; an object with an index signature that does not breaks its braces around the
 * signature on a line of its own; a list of imported names breaks into one name per line with a
 * trailing comma; and a TSDoc comment is wrapped within the width.
 */
#[Internal]
final readonly class TypeScriptLayout
{
    public const int WIDTH = 100;

    /**
     * `<head> <type>;` at the indent, such as `  readonly title: string;` or `export type A = B;`,
     * broken as Prettier breaks it when it does not fit.
     *
     * @return list<string>
     */
    public static function statement(string $head, TypeExpression $type, int $indent): array
    {
        $pad = str_repeat(' ', $indent);
        $flat = $pad.$head.' '.$type->flat().';';

        if (strlen($flat) <= self::WIDTH) {
            return [$flat];
        }

        if ($type->union) {
            $inner = str_repeat(' ', $indent + 2);
            $next = $inner.$type->flat().';';

            if (strlen($next) <= self::WIDTH) {
                return [$pad.$head, $next];
            }

            $members = array_map(static fn (TypeExpression $member): string => $inner.'| '.$member->flat(), $type->arguments);
            $members[count($members) - 1] .= ';';

            return [$pad.$head, ...$members];
        }

        if ($type->value instanceof TypeExpression) {
            return [$pad.$head.' {', ...self::statement('readonly [key: string]:', $type->value, $indent + 2), $pad.'};'];
        }

        return self::expression($type, $indent, ';', $pad.$head.' ');
    }

    /**
     * The type at the indent, after the lead on its first line (the indent when it is null), and
     * followed by the suffix, broken where it does not fit.
     *
     * @return list<string>
     */
    private static function expression(TypeExpression $type, int $indent, string $suffix, ?string $lead = null): array
    {
        $pad = str_repeat(' ', $indent);
        $lead ??= $pad;
        $flat = $lead.$type->flat().$suffix;

        if (strlen($flat) <= self::WIDTH || $type->union || $type->element instanceof TypeExpression || $type->value instanceof TypeExpression || $type->arguments === []) {
            return [$flat];
        }

        $lines = [$lead.$type->text.'<'];
        $last = count($type->arguments) - 1;

        foreach ($type->arguments as $index => $argument) {
            array_push($lines, ...self::expression($argument, $indent + 2, $index === $last ? '' : ','));
        }

        $lines[] = $pad.'>'.$suffix;

        return $lines;
    }

    /**
     * `import type { <names> } from '<module>';`, the names sorted.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function import(array $names, string $module): array
    {
        sort($names, SORT_STRING);
        $flat = sprintf("import type { %s } from '%s';", implode(', ', $names), $module);

        if (strlen($flat) <= self::WIDTH) {
            return [$flat];
        }

        return ['import type {', ...array_map(static fn (string $name): string => '  '.$name.',', $names), sprintf("} from '%s';", $module)];
    }

    /**
     * A TSDoc comment of the text at the indent, wrapped within the width: on one line when it
     * fits there.
     *
     * @return list<string>
     */
    public static function comment(string $text, int $indent): array
    {
        $pad = str_repeat(' ', $indent);
        $text = str_replace('*/', '*\/', trim((string) preg_replace('/\s+/', ' ', $text)));
        $single = $pad.'/** '.$text.' */';

        if (strlen($single) <= self::WIDTH) {
            return [$single];
        }

        $lines = [$pad.'/**'];
        $line = '';

        foreach (explode(' ', $text) as $word) {
            if ($line !== '' && strlen($pad.' * '.$line.' '.$word) > self::WIDTH) {
                $lines[] = $pad.' * '.$line;
                $line = $word;

                continue;
            }

            $line = $line === '' ? $word : $line.' '.$word;
        }

        $lines[] = $pad.' * '.$line;
        $lines[] = $pad.' */';

        return $lines;
    }

    /**
     * A member's key: as it is when it is an identifier, quoted with single quotes otherwise.
     */
    public static function key(string $name): string
    {
        return preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', $name) === 1
            ? $name
            : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $name)."'";
    }
}
