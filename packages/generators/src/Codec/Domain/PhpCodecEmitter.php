<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Override;

/**
 * Writes the JSON codec of one contract version (GUARDRAILS 2.2): a final readonly class that
 * implements the contract JsonCodec, whose encode() gives the canonical JSON of a DTO as a caller with a classification access may see it,
 * and whose decode() reads a document into the DTO, checking every rule of every value. The codec
 * says only which keys the contract has and what their rules are; the core's JsonText and
 * JsonValues fix the JSON form of each kind of value, so no codec reflects on anything at run time.
 *
 * The codec lives in a Boundary namespace, because it reads the `mixed` of a decoded document. It
 * has a private encode and decode method per object of the contract.
 *
 * The output is formatted the way Pint, Rector and PHPStan level 10 accept it unchanged.
 */
#[Internal]
final readonly class PhpCodecEmitter
{
    private const string JSON_CODEC = JsonCodec::class;

    /**
     * The namespace of the core's codecs feature, whose classes the generated code uses at run
     * time. The emitter only writes their names; it does not use them.
     */
    private const string CODECS = 'Cbox\Cms\Core\Codecs';

    private const string JSON_TEXT = self::CODECS.'\Boundary\JsonText';

    private const string JSON_VALUES = self::CODECS.'\Boundary\JsonValues';

    private const string DECODING_FAILED = self::CODECS.'\Domain\DecodingFailed';

    private const string ENCODING_FAILED = self::CODECS.'\Domain\EncodingFailed';

    private const string FIELD_PATH = FieldPath::class;

    /**
     * @param  PhpLocation  $dtos  where the contract's DTOs are
     * @param  PhpLocation  $codecs  where the codec goes
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public static function emit(CodecContract $contract, PhpLocation $dtos, PhpLocation $codecs): GeneratedFile
    {
        $root = $contract->root;
        $objects = [];

        foreach ($root->objects() as $object) {
            $objects[$object->className] ??= $object;
        }

        $classes = [
            self::JSON_CODEC,
            Override::class,
            self::JSON_TEXT,
            self::JSON_VALUES,
            self::DECODING_FAILED,
            self::ENCODING_FAILED,
            self::FIELD_PATH,
            PhpSource::CLASSIFICATION_ACCESS,
            'stdClass',
        ];
        $methods = [];

        foreach ($objects as $object) {
            $classes[] = $dtos->className($object->className);

            foreach ($object->properties as $property) {
                PhpSource::assertKey($property, $object);
                PhpSource::assertRules($property->value, $object->className.'::$'.$property->name);
                array_push($classes, ...PhpSource::imports($property->value));

                if ($property->omittable()) {
                    $classes[] = PhpSource::OMITTED;
                }
            }

            $methods = [...$methods, '', ...self::encoder($object), '', ...self::decoder($object, $object === $root)];
        }

        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace '.$codecs->namespace.';',
            '',
            ...PhpSource::uses($classes, $codecs->namespace),
            '',
            '/**',
            ...array_map(static fn (string $line): string => rtrim(' * '.$line), $contract->summary),
            ' *',
            ' * @implements JsonCodec<'.$root->className.'>',
            ' */',
            'final readonly class '.$contract->codecClass.' implements JsonCodec',
            '{',
            '    /** The contract version this codec reads and writes. */',
            '    public const int VERSION = '.$contract->version.';',
            '',
            '    /**',
            '     * The canonical JSON of $dto as a caller with $access may see it: sorted keys, no',
            '     * whitespace, and without every field classified above the access (PRD 12.2) or held as',
            '     * Omitted.',
            '     *',
            '     * @param  '.$root->className.'  $dto',
            '     *',
            '     * @throws EncodingFailed when the DTO holds a value that has no form in the contract',
            '     */',
            '    #[Override]',
            '    public function encode(object $dto, ClassificationAccess $access): string',
            '    {',
            sprintf(
                '        return JsonText::encode($this->%s(%s));',
                self::encoderName($root),
                $root->classified() ? '$dto->visibleTo($access)' : '$dto',
            ),
            '    }',
            '',
            '    /**',
            '     * The DTO a JSON document holds, read as a caller with $access: a field classified above',
            '     * the access must be absent, and is Omitted, as is an optional field that is absent.',
            '     *',
            '     * @throws DecodingFailed with json_malformed or json_invalid',
            '     */',
            '    #[Override]',
            sprintf('    public function decode(string $json, ClassificationAccess $access): %s', $root->className),
            '    {',
            sprintf(
                '        return $this->%s(JsonText::decode($json)%s);',
                self::decoderName($root),
                $root->classified() ? ', $access' : '',
            ),
            '    }',
            ...$methods,
            '}',
        ];

        return new GeneratedFile($codecs->directory.'/'.$contract->codecClass.'.php', implode("\n", $lines)."\n");
    }

    /**
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function encoder(CodecObject $object): array
    {
        $lines = [
            sprintf('    private function %s(%s $object): stdClass', self::encoderName($object), $object->className),
            '    {',
            '        $json = new stdClass;',
        ];

        foreach ($object->properties as $property) {
            $read = '$object->'.$property->name;
            $written = self::written($property->value, $read, 0);

            if (! $property->required && $written !== $read) {
                $written = $property->value->kind->isClass()
                    ? sprintf('%s instanceof %s ? %s : null', $read, PhpSource::native($property->value), $written)
                    : sprintf('%s === null ? null : %s', $read, $written);
            }

            $assignment = sprintf('$json->%s = %s;', $property->key, $written);

            if ($property->omittable()) {
                $lines[] = '';
                $lines[] = sprintf('        if (! %s instanceof Omitted) {', $read);
                $lines[] = '            '.$assignment;
                $lines[] = '        }';
                $lines[] = '';
            } else {
                $lines[] = '        '.$assignment;
            }
        }

        $lines = self::collapseBlankLines($lines);

        return [...$lines, ...(end($lines) === '' ? [] : ['']), '        return $json;', '    }'];
    }

    /**
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function decoder(CodecObject $object, bool $root): array
    {
        $path = $root ? 'null' : '$path';
        $keys = PhpSource::strings(array_map(static fn (CodecProperty $property): string => $property->key, $object->properties));
        $parameters = $root ? ['stdClass $value'] : ['mixed $value', 'FieldPath $path'];

        if ($object->classified()) {
            $parameters[] = 'ClassificationAccess $access';
        }

        $lines = [
            sprintf('    private function %s(%s): %s', self::decoderName($object), implode(', ', $parameters), $object->className),
            '    {',
            sprintf('        $object = JsonValues::object($value, %s, %s);', $path, $keys),
            '',
            sprintf('        return new %s(', $object->className),
        ];

        foreach ($object->properties as $property) {
            $lines[] = sprintf('            %s: %s,', $property->name, self::field($property, $path));
        }

        return [...$lines, '        );', '    }'];
    }

    /**
     * The expression that reads a property from `$object`, the decoded object's fields.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function field(CodecProperty $property, string $path): string
    {
        $read = self::reader($property->value, 0);
        $key = PhpSource::string($property->key);

        if (! $property->withheld() || ! $property->classification instanceof ClassificationAccess) {
            return sprintf('JsonValues::%s($object, %s, %s, %s)', $property->required ? 'required' : 'nullable', $key, $path, $read);
        }

        return sprintf(
            'JsonValues::%s($object, %s, %s, ClassificationAccess::%s, $access, %s)',
            $property->required ? 'requiredClassified' : 'nullableClassified',
            $key,
            $path,
            $property->classification->name,
            $read,
        );
    }

    /**
     * A callable that reads a value of the kind from `mixed` at a FieldPath: a closure, or the
     * decoder of an object as a first-class callable.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function reader(CodecValue $value, int $depth): string
    {
        $object = $value->object;

        if ($value->kind === CodecKind::Object && $object instanceof CodecObject && ! $object->classified()) {
            return sprintf('$this->%s(...)', self::decoderName($object));
        }

        return sprintf(
            '%sfn (mixed %s, FieldPath %s): %s => %s',
            self::usesThis($value) ? '' : 'static ',
            self::valueVariable($depth),
            self::pathVariable($depth),
            PhpSource::native($value),
            self::read($value, $depth),
        );
    }

    /**
     * The expression that reads a value of the kind from the variables of $depth.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function read(CodecValue $value, int $depth): string
    {
        $raw = self::valueVariable($depth);
        $at = self::pathVariable($depth);

        return match ($value->kind) {
            CodecKind::Text => self::call('text', [$raw, $at, ...self::named($value, [
                'minLength' => ValidationRuleName::MinLength,
                'maxLength' => ValidationRuleName::MaxLength,
            ], ['format' => ValidationRuleName::Format])]),
            CodecKind::Integer => self::call('integer', [$raw, $at, ...self::named($value, [
                'min' => ValidationRuleName::Min,
                'max' => ValidationRuleName::Max,
            ], [])]),
            CodecKind::Decimal => self::call('decimal', [$raw, $at, ...self::precisionAndScale($value), ...self::named($value, [], [
                'min' => ValidationRuleName::Min,
                'max' => ValidationRuleName::Max,
            ])]),
            CodecKind::Boolean => self::call('boolean', [$raw, $at]),
            CodecKind::Date, CodecKind::Datetime => self::call($value->kind->value, [$raw, $at, ...self::named($value, [], [
                'min' => ValidationRuleName::Min,
                'max' => ValidationRuleName::Max,
            ])]),
            CodecKind::Choice => self::call('choice', [$raw, $at, PhpSource::strings(PhpSource::choices($value))]),
            CodecKind::PortableText => self::call('portableText', [$raw, $at, ...array_map(
                static fn (ValidationRuleName $rule): string => PhpSource::strings(PhpSource::rule($value, $rule)->arguments ?? []),
                [ValidationRuleName::Styles, ValidationRuleName::Marks, ValidationRuleName::Lists, ValidationRuleName::Links],
            )]),
            CodecKind::Object => sprintf(
                '$this->%s(%s, %s%s)',
                self::decoderName(self::object($value)),
                $raw,
                $at,
                self::object($value)->classified() ? ', $access' : '',
            ),
            CodecKind::List => self::call('list', [
                $raw,
                $at,
                self::reader(self::item($value), $depth + 1),
                ...self::named($value, ['minItems' => ValidationRuleName::MinItems, 'maxItems' => ValidationRuleName::MaxItems], []),
                ...(PhpSource::rule($value, ValidationRuleName::Distinct) instanceof ValidationRule ? ['distinct: true'] : []),
            ]),
            CodecKind::Id => self::call('id', [$raw, $at, PhpSource::native($value).'::fromString(...)']),
            CodecKind::Enum => self::call('enum', [$raw, $at, PhpSource::native($value).'::class']),
        };
    }

    /**
     * The expression that writes the value in $read to JSON.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function written(CodecValue $value, string $read, int $depth): string
    {
        return match ($value->kind) {
            CodecKind::Text, CodecKind::Integer, CodecKind::Boolean, CodecKind::Choice => $read,
            CodecKind::Decimal => sprintf('JsonValues::encodeDecimal(%s, %s)', $read, implode(', ', self::precisionAndScale($value))),
            CodecKind::Date => sprintf('JsonValues::encodeDate(%s)', $read),
            CodecKind::Datetime => sprintf('JsonValues::encodeDatetime(%s)', $read),
            CodecKind::PortableText => sprintf('JsonValues::encodeFieldValue(%s)', $read),
            CodecKind::Object => sprintf('$this->%s(%s)', self::encoderName(self::object($value)), $read),
            CodecKind::List => self::writtenList(self::item($value), $read, $depth + 1),
            CodecKind::Id => $read.'->toString()',
            CodecKind::Enum => $read.'->value',
        };
    }

    /**
     * The expression that writes the list in $read, each item at $depth.
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function writtenList(CodecValue $item, string $read, int $depth): string
    {
        $variable = self::valueVariable($depth);
        $written = self::written($item, $variable, $depth);

        if ($written === $variable) {
            return $read;
        }

        if ($item->kind === CodecKind::Object) {
            return sprintf('array_map($this->%s(...), %s)', self::encoderName(self::object($item)), $read);
        }

        return sprintf(
            'array_map(%sfn (%s %s): mixed => %s, %s)',
            self::usesThis($item) ? '' : 'static ',
            PhpSource::native($item),
            $variable,
            $written,
            $read,
        );
    }

    /**
     * The precision and the scale of a decimal's `decimal` rule, as PHP literals.
     *
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function precisionAndScale(CodecValue $value): array
    {
        $arguments = PhpSource::rule($value, ValidationRuleName::Decimal)->arguments ?? [];

        if (count($arguments) !== 2) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, 'A decimal needs a `decimal` rule with its precision and scale.');
        }

        return array_map(PhpSource::integer(...), $arguments);
    }

    /**
     * The named arguments of the rules a value has, as `name: literal`: the first argument of each
     * rule, an integer literal for $integers and a string literal for $strings.
     *
     * @param  array<string, ValidationRuleName>  $integers  argument name to rule
     * @param  array<string, ValidationRuleName>  $strings  argument name to rule
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function named(CodecValue $value, array $integers, array $strings): array
    {
        $arguments = [];

        foreach ([...$integers, ...$strings] as $name => $rule) {
            $argument = PhpSource::rule($value, $rule)->arguments[0] ?? null;

            if ($argument !== null) {
                $arguments[] = $name.': '.(array_key_exists($name, $integers) ? PhpSource::integer($argument) : PhpSource::string($argument));
            }
        }

        return $arguments;
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

    /**
     * @param  list<string>  $arguments
     */
    private static function call(string $method, array $arguments): string
    {
        return sprintf('JsonValues::%s(%s)', $method, implode(', ', $arguments));
    }

    /**
     * The variable of the value in a reader or writer at $depth: `$value`, then `$item`, then
     * `$item2`.
     */
    private static function valueVariable(int $depth): string
    {
        return match ($depth) {
            0 => '$value',
            1 => '$item',
            default => '$item'.$depth,
        };
    }

    /**
     * The variable of the value's path in a reader at $depth: `$at`, then `$itemAt`, then `$item2At`.
     */
    private static function pathVariable(int $depth): string
    {
        return $depth === 0 ? '$at' : self::valueVariable($depth).'At';
    }

    private static function usesThis(CodecValue $value): bool
    {
        return $value->kind === CodecKind::Object || ($value->item instanceof CodecValue && self::usesThis($value->item));
    }

    private static function encoderName(CodecObject $object): string
    {
        return 'encode'.$object->className;
    }

    private static function decoderName(CodecObject $object): string
    {
        return 'decode'.$object->className;
    }

    /**
     * Lines without two blank lines in a row, and without a blank line after an opening brace.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function collapseBlankLines(array $lines): array
    {
        $collapsed = [];

        foreach ($lines as $line) {
            $previous = end($collapsed);

            if ($line === '' && ($previous === '' || ($previous !== false && str_ends_with($previous, '{')))) {
                continue;
            }

            $collapsed[] = $line;
        }

        return $collapsed;
    }
}
