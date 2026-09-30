<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain;

use BackedEnum;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ArrayLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\BooleanLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\LiteralPrinter;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\NumberLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ObjectLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Property;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Reference;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\StringLiteral;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * Writes the TypeScript module of one contract version (PRD 11.12, GUARDRAILS 2.2): the JSON form
 * of the contract as TypeScript types, with the keys the JSON has, and a runtime validator,
 * `validate<Name>()`, that checks a JSON value against every rule the contract's PHP codec checks,
 * held as data (an ObjectRule of the runtime module) and checked by the runtime's validate(). The
 * module imports nothing but the runtime module, so a browser loads it as it is.
 *
 * The TypeScript names: the root is the codec's class without `Codec`, such as `ShopProductV1` or
 * `ReceiptV1`; a generated DTO keeps its class name, such as `ShopProductV1Dimensions`; an object
 * bound to a class of the contracts is the class's short name and the version, such as
 * `ConsistencyTokenV1`; the values of a select are the type `<Object><Property>Choice`, and those of
 * a bound enum the enum's short name. Two declarations of one name are refused with
 * generate_name_collision.
 *
 * Each key of an object is:
 *
 * - `key: T` when it is required,
 * - `key: T | null` when it is required and nullable,
 * - `key?: T` when it has a default or is withheld above a classification (PRD 12.2), and
 * - `key?: T | null` when it is optional.
 *
 * The validator checks the keys of an object in the order the PHP codec reads them, after refusing
 * a key the contract does not have, so both name the same first value that breaks a rule. The one
 * thing it does not check is a field's classification: a withheld field may be missing, whoever
 * reads the document.
 *
 * The output is formatted the way the shared Prettier configuration prints it, and passes tsc and
 * ESLint with the shared configuration unchanged.
 */
#[Internal]
final readonly class TypeScriptEmitter
{
    /** Prettier's printWidth in js/tooling/prettier.js. */
    public const int PRINT_WIDTH = LiteralPrinter::PRINT_WIDTH;

    /** The largest integer a JavaScript number holds exactly, Number.MAX_SAFE_INTEGER. */
    private const int MAX_SAFE_INTEGER = 9007199254740991;

    /**
     * @param  string  $path  the path of the module below the root
     * @param  string  $runtime  the specifier of the runtime module, relative to the module, such as `../validation`
     * @param  list<string>  $header  the lines of the comment the module starts with
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput or GenerateErrorCode::NameCollision
     */
    public static function emit(CodecContract $contract, string $path, string $runtime, array $header): GeneratedFile
    {
        $objects = [];

        foreach ($contract->root->objects() as $object) {
            $objects[$object->className] ??= $object;
        }

        $names = self::names($contract, array_values($objects));
        $root = $names[$contract->root->className];
        $aliases = [];
        $interfaces = [];

        foreach ($objects as $object) {
            $interfaces = [...$interfaces, '', ...self::interface($object, $names, $aliases)];
        }

        $declared = [...array_values($names), ...array_keys($aliases)];

        foreach (array_count_values($declared) as $name => $count) {
            if ($count > 1) {
                throw GenerationFailed::because(GenerateErrorCode::NameCollision, sprintf(
                    'The TypeScript module of %s declares %s more than once. Rename a group or a field so their handles differ in more than underscores.',
                    $contract->codecClass,
                    $name,
                ));
            }
        }

        $rules = [];

        foreach (array_reverse($objects) as $object) {
            $name = self::ruleName($names[$object->className]);
            $literal = self::objectRule($object, $names);
            $rules = [...$rules, '', ...LiteralPrinter::constant($name, 'ObjectRule', $literal)];
        }

        $imports = ['validate', 'type ObjectRule'];

        if (self::uses($objects, CodecKind::Fields)) {
            $imports[] = 'type FieldValues';
        }

        if (self::uses($objects, CodecKind::PortableText)) {
            $imports[] = 'type PortableText';
        }

        $imports[] = 'type Validation';

        $lines = [
            ...array_map(static fn (string $line): string => rtrim('// '.$line), $header),
            '',
            ...self::import($imports, $runtime),
            ...self::aliases($aliases),
            ...$interfaces,
            ...$rules,
            '',
            '/**',
            sprintf(' * Checks a JSON value against %s, contract version %d: every key and every rule,', $root, $contract->version),
            sprintf(' * as the kernel\'s codec %s checks them. Gives the value typed, or the first', $contract->codecClass),
            ' * value that breaks a rule and why.',
            ' */',
            ...self::call('export function validate'.$root, ['value: unknown'], ': Validation<'.$root.'> {'),
            ...self::call('  return validate<'.$root.'>', ['value', self::ruleName($root)], ';'),
            '}',
        ];

        return new GeneratedFile($path, implode("\n", $lines)."\n");
    }

    /**
     * The TypeScript name of each object, by its class name.
     *
     * @param  list<CodecObject>  $objects
     * @return array<string, string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function names(CodecContract $contract, array $objects): array
    {
        if (preg_match('/\A(.+)Codec(V[0-9]+)\z/', $contract->codecClass, $match) !== 1) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('The codec class %s does not end in Codec and its version, such as ReceiptCodecV1.', $contract->codecClass));
        }

        $names = [];

        foreach ($objects as $object) {
            $names[$object->className] = match (true) {
                $object === $contract->root => $match[1].$match[2],
                $object->class !== null => $object->className.'V'.$contract->version,
                default => $object->className,
            };
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, list<string>>  $aliases  the union types of choices and enums, by name, which this adds to
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function interface(CodecObject $object, array $names, array &$aliases): array
    {
        $name = $names[$object->className];
        $summary = array_values(array_filter(array_map(PhpSource::docText(...), $object->summary), static fn (string $line): bool => $line !== ''));
        $lines = [...self::comment($summary === [] ? [$name.'.'] : $summary, ''), 'export interface '.$name.' {'];

        foreach ($object->properties as $property) {
            $description = PhpSource::docText($property->description);

            if ($description !== '') {
                $lines = [...$lines, ...self::comment([$description], '  ')];
            }

            $type = self::type($property->value, $name.RecordContracts::className($property->key), $names, $aliases);
            [$optional, $nullable] = match (self::presence($property)) {
                'required' => [false, false],
                'present' => [false, true],
                'omittable' => [true, false],
                default => [true, true],
            };

            array_push($lines, ...self::member(
                '  '.self::key($property->key).($optional ? '?' : '').':',
                $nullable ? [$type, 'null'] : [$type],
            ));
        }

        $lines[] = '}';

        return $lines;
    }

    /**
     * A doc comment of the lines, each wrapped at the print width: on one line when it is one line
     * that fits.
     *
     * @param  list<string>  $text
     * @return list<string>
     */
    private static function comment(array $text, string $indent): array
    {
        if (count($text) === 1 && strlen($indent.'/** '.$text[0].' */') <= self::PRINT_WIDTH) {
            return [$indent.'/** '.$text[0].' */'];
        }

        $lines = [$indent.'/**'];

        foreach ($text as $line) {
            foreach (explode("\n", wordwrap($line, self::PRINT_WIDTH - strlen($indent) - 3, "\n", false)) as $wrapped) {
                $lines[] = $indent.' * '.$wrapped;
            }
        }

        $lines[] = $indent.' */';

        return $lines;
    }

    /**
     * A member of an interface, as Prettier breaks one that does not fit: a type that is not a
     * union, or a type name and null, stays on the line; another union goes to the next line, and
     * when it does not fit there either, gets one member per line.
     *
     * @param  list<string>  $union
     * @return list<string>
     */
    private static function member(string $key, array $union): array
    {
        $type = implode(' | ', $union);
        $line = $key.' '.$type.';';
        $hugged = count($union) === 2 && $union[1] === 'null' && preg_match('/\A[A-Z][A-Za-z0-9_]*\z/', $union[0]) === 1;

        if (strlen($line) <= self::PRINT_WIDTH || count($union) < 2 || $hugged) {
            return [$line];
        }

        if (4 + strlen($type) + 1 <= self::PRINT_WIDTH) {
            return [$key, '    '.$type.';'];
        }

        $lines = [$key];

        foreach ($union as $member) {
            $lines[] = '    | '.$member;
        }

        $lines[] = array_pop($lines).';';

        return $lines;
    }

    /**
     * The TypeScript type of a value.
     *
     * @param  string  $alias  the name of the union of a select's values
     * @param  array<string, string>  $names
     * @param  array<string, list<string>>  $aliases
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function type(CodecValue $value, string $alias, array $names, array &$aliases): string
    {
        switch ($value->kind) {
            case CodecKind::Integer:
            case CodecKind::IntegerValue:
                return 'number';
            case CodecKind::Boolean:
                return 'boolean';
            case CodecKind::PortableText:
                return 'PortableText';
            case CodecKind::Fields:
                return 'FieldValues';
            case CodecKind::Object:
                return $names[self::object($value)->className];
            case CodecKind::List:
                return 'readonly '.self::type(self::item($value), $alias, $names, $aliases).'[]';
            case CodecKind::Choice:
                $aliases[$alias.'Choice'] = array_map(static fn (string $choice): string => new StringLiteral($choice)->flat(), PhpSource::choices($value));

                return $alias.'Choice';
            case CodecKind::Enum:
                $name = PhpSource::shortName((string) $value->class);
                $aliases[$name] = array_map(static fn (int|string $case): string => is_int($case) ? (string) $case : new StringLiteral($case)->flat(), self::enumValues($value));

                return $name;
            default:
                return 'string';
        }
    }

    /**
     * The union types of the choices and the enums, sorted by name.
     *
     * @param  array<string, list<string>>  $aliases
     * @return list<string>
     */
    private static function aliases(array $aliases): array
    {
        ksort($aliases, SORT_STRING);
        $lines = [];

        foreach ($aliases as $name => $members) {
            $declaration = 'export type '.$name.' =';
            $line = $declaration.' '.implode(' | ', $members).';';
            $lines[] = '';
            $lines[] = '/** The values of '.$name.'. */';

            if (strlen($line) <= self::PRINT_WIDTH) {
                $lines[] = $line;

                continue;
            }

            $lines[] = $declaration;

            if (2 + strlen(implode(' | ', $members)) + 1 <= self::PRINT_WIDTH) {
                $lines[] = '  '.implode(' | ', $members).';';

                continue;
            }

            foreach ($members as $member) {
                $lines[] = '  | '.$member;
            }

            $lines[] = array_pop($lines).';';
        }

        return $lines;
    }

    /**
     * The presence of a key in the runtime's words (Presence in the runtime module).
     */
    private static function presence(CodecProperty $property): string
    {
        return match (true) {
            $property->withheld() => $property->required ? 'omittable' : 'optional',
            $property->required => $property->nullable ? 'present' : 'required',
            $property->default !== null => $property->nullable ? 'optional' : 'omittable',
            default => 'optional',
        };
    }

    /**
     * The ObjectRule of an object: its properties in the order the PHP codec reads them.
     *
     * @param  array<string, string>  $names
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function objectRule(CodecObject $object, array $names): ObjectLiteral
    {
        return new ObjectLiteral([new Property('properties', new ArrayLiteral(array_map(
            static function (CodecProperty $property) use ($object, $names): ObjectLiteral {
                PhpSource::assertKey($property, $object);
                PhpSource::assertRules($property->value, $object->className.'::$'.$property->name);

                return new ObjectLiteral([
                    new Property('key', new StringLiteral($property->key)),
                    new Property('presence', new StringLiteral(self::presence($property))),
                    new Property('value', self::valueRule($property->value, $names)),
                ]);
            },
            $object->constructorOrder(),
        )))]);
    }

    /**
     * The rule of a value, with every rule of its kind.
     *
     * @param  array<string, string>  $names
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function valueRule(CodecValue $value, array $names): ObjectLiteral
    {
        $kind = static fn (string $kind): Property => new Property('kind', new StringLiteral($kind));

        return new ObjectLiteral(match ($value->kind) {
            CodecKind::Text => [
                $kind('text'),
                ...self::integers($value, ['minLength' => ValidationRuleName::MinLength, 'maxLength' => ValidationRuleName::MaxLength]),
                ...self::strings($value, ['format' => ValidationRuleName::Format]),
            ],
            CodecKind::Integer, CodecKind::IntegerValue => [$kind('integer'), ...self::bounds($value)],
            CodecKind::Fields => [$kind('fields')],
            CodecKind::Decimal => [
                $kind('decimal'),
                ...self::precisionAndScale($value),
                ...self::strings($value, ['min' => ValidationRuleName::Min, 'max' => ValidationRuleName::Max]),
            ],
            CodecKind::Boolean => [$kind('boolean')],
            CodecKind::Date, CodecKind::Datetime => [
                $kind($value->kind->value),
                ...self::strings($value, ['min' => ValidationRuleName::Min, 'max' => ValidationRuleName::Max]),
            ],
            CodecKind::Choice => [$kind('choice'), new Property('choices', ArrayLiteral::strings(PhpSource::choices($value)))],
            CodecKind::PortableText => [
                $kind('portable_text'),
                ...array_map(
                    static fn (string $key, ValidationRuleName $rule): Property => new Property($key, ArrayLiteral::strings(PhpSource::rule($value, $rule)->arguments ?? [])),
                    ['styles', 'marks', 'lists', 'links'],
                    [ValidationRuleName::Styles, ValidationRuleName::Marks, ValidationRuleName::Lists, ValidationRuleName::Links],
                ),
            ],
            CodecKind::Object => [$kind('object'), new Property('object', new Reference(self::ruleName($names[self::object($value)->className])))],
            CodecKind::List => [
                $kind('list'),
                new Property('item', self::valueRule(self::item($value), $names)),
                ...self::integers($value, ['minItems' => ValidationRuleName::MinItems, 'maxItems' => ValidationRuleName::MaxItems]),
                ...(PhpSource::rule($value, ValidationRuleName::Distinct) instanceof ValidationRule ? [new Property('distinct', new BooleanLiteral(true))] : []),
            ],
            CodecKind::Id, CodecKind::Value => [
                $kind('string'),
                ...($value->form?->pattern === null ? [] : [new Property('pattern', new StringLiteral($value->form->pattern))]),
                ...($value->form?->minLength === null ? [] : [new Property('minLength', NumberLiteral::of($value->form->minLength))]),
                ...($value->form?->maxLength === null ? [] : [new Property('maxLength', NumberLiteral::of($value->form->maxLength))]),
            ],
            CodecKind::Enum => [$kind('enum'), new Property('values', new ArrayLiteral(array_map(
                static fn (int|string $case): NumberLiteral|StringLiteral => is_int($case) ? NumberLiteral::of($case) : new StringLiteral($case),
                self::enumValues($value),
            )))],
        });
    }

    /**
     * The integer arguments of the rules a value has, each under its key.
     *
     * @param  array<string, ValidationRuleName>  $rules
     * @return list<Property>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function integers(CodecValue $value, array $rules): array
    {
        $properties = [];

        foreach ($rules as $key => $rule) {
            $argument = PhpSource::rule($value, $rule)->arguments[0] ?? null;

            if ($argument !== null) {
                $properties[] = new Property($key, NumberLiteral::of((int) PhpSource::integer($argument)));
            }
        }

        return $properties;
    }

    /**
     * The string arguments of the rules a value has, each under its key.
     *
     * @param  array<string, ValidationRuleName>  $rules
     * @return list<Property>
     */
    private static function strings(CodecValue $value, array $rules): array
    {
        $properties = [];

        foreach ($rules as $key => $rule) {
            $argument = PhpSource::rule($value, $rule)->arguments[0] ?? null;

            if ($argument !== null) {
                $properties[] = new Property($key, new StringLiteral($argument));
            }
        }

        return $properties;
    }

    /**
     * The bounds of an integer as JavaScript numbers. A JavaScript number holds an integer exactly
     * only up to Number.MAX_SAFE_INTEGER, and the validator refuses any other: a bound outside that
     * range that every such integer meets is left out, and one that none meets is Infinity.
     *
     * @return list<Property>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function bounds(CodecValue $value): array
    {
        $properties = [];

        foreach (['min' => ValidationRuleName::Min, 'max' => ValidationRuleName::Max] as $key => $rule) {
            $argument = PhpSource::rule($value, $rule)->arguments[0] ?? null;

            if ($argument === null) {
                continue;
            }

            $bound = (int) PhpSource::integer($argument);
            $safe = abs($bound) <= self::MAX_SAFE_INTEGER;
            $alwaysMet = $key === 'min' ? $bound < 0 : $bound > 0;

            if ($safe) {
                $properties[] = new Property($key, NumberLiteral::of($bound));
            } elseif (! $alwaysMet) {
                $properties[] = new Property($key, new NumberLiteral($bound < 0 ? '-Infinity' : 'Infinity'));
            }
        }

        return $properties;
    }

    /**
     * @return list<Property>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function precisionAndScale(CodecValue $value): array
    {
        $arguments = PhpSource::rule($value, ValidationRuleName::Decimal)->arguments ?? [];

        if (count($arguments) !== 2) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, 'A decimal needs a `decimal` rule with its precision and scale.');
        }

        return [
            new Property('precision', NumberLiteral::of((int) PhpSource::integer($arguments[0]))),
            new Property('scale', NumberLiteral::of((int) PhpSource::integer($arguments[1]))),
        ];
    }

    /**
     * The values of a bound enum.
     *
     * @return list<int|string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function enumValues(CodecValue $value): array
    {
        $class = (string) $value->class;

        if (! is_subclass_of($class, BackedEnum::class)) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('%s is not a backed enum.', $class));
        }

        return array_map(static fn (BackedEnum $case): int|string => $case->value, $class::cases());
    }

    /**
     * Whether a property of the objects, or an item of one, is a value of the kind, whose type the
     * module imports from the runtime module.
     *
     * @param  array<string, CodecObject>  $objects
     */
    private static function uses(array $objects, CodecKind $kind): bool
    {
        return array_any($objects, static fn (CodecObject $object): bool => array_any(
            $object->properties,
            static fn (CodecProperty $property): bool => $property->value->kind === $kind || $property->value->item?->kind === $kind,
        ));
    }

    /**
     * The import of the runtime module: on one line when it fits, and otherwise one name per line.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function import(array $names, string $runtime): array
    {
        $from = "} from '".$runtime."';";
        $line = 'import { '.implode(', ', $names).' '.$from;

        if (strlen($line) <= self::PRINT_WIDTH) {
            return [$line];
        }

        return ['import {', ...array_map(static fn (string $name): string => '  '.$name.',', $names), $from];
    }

    /**
     * A call or a signature: its arguments on one line when they fit, and otherwise one per line
     * with a trailing comma, as Prettier breaks them.
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private static function call(string $head, array $arguments, string $tail): array
    {
        $line = $head.'('.implode(', ', $arguments).')'.$tail;

        if (strlen($line) <= self::PRINT_WIDTH) {
            return [$line];
        }

        $indent = str_repeat(' ', strlen($head) - strlen(ltrim($head)));

        return [
            $head.'(',
            ...array_map(static fn (string $argument): string => $indent.'  '.$argument.',', $arguments),
            $indent.')'.$tail,
        ];
    }

    /**
     * A key of an interface, quoted when it is not an identifier.
     */
    private static function key(string $key): string
    {
        return new Property($key, new BooleanLiteral(true))->key();
    }

    private static function ruleName(string $name): string
    {
        return lcfirst($name).'Rule';
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function object(CodecValue $value): CodecObject
    {
        return $value->object ?? throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, 'An object value has no object.');
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function item(CodecValue $value): CodecValue
    {
        return $value->item ?? throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, 'A list value has no item.');
    }
}
