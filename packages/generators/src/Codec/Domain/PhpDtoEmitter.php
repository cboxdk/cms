<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Codec\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecObject;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecProperty;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecValue;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * Writes the DTO classes of a codec contract (PRD 8.9, GUARDRAILS 2.2): one final readonly class per
 * object, in its own file, with a promoted constructor property per property in the order of their
 * JSON keys. A class with a property that a caller may be denied has visibleTo(), which gives the
 * DTO as a caller with a classification access may see it (PRD 12.2).
 *
 * The output is formatted the way Pint, Rector and PHPStan level 10 accept it unchanged.
 */
#[Internal]
final readonly class PhpDtoEmitter
{
    /**
     * @return list<GeneratedFile>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    public static function emit(CodecContract $contract, PhpLocation $location): array
    {
        $files = [];

        foreach ($contract->root->objects() as $object) {
            $files[$object->className] ??= new GeneratedFile(
                $location->directory.'/'.$object->className.'.php',
                self::file($object, $location->namespace),
            );
        }

        return array_values($files);
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function file(CodecObject $object, string $namespace): string
    {
        $classes = [];
        $parameters = [];
        $docs = [];
        $omitted = false;

        foreach ($object->properties as $property) {
            PhpSource::assertKey($property, $object);
            PhpSource::assertRules($property->value, $object->className.'::$'.$property->name);
            array_push($classes, ...PhpSource::imports($property->value));
            $omitted = $omitted || $property->omittable();
            $parameters[] = sprintf('        public %s $%s,', PhpSource::propertyNative($property), $property->name);
            $docs[] = sprintf('     * @param  %s  $%s  %s', PhpSource::propertyDoc($property), $property->name, PhpSource::docText($property->description));
        }

        $classified = $object->classified();

        if ($omitted || $classified) {
            $classes[] = PhpSource::OMITTED;
        }

        if ($classified) {
            $classes[] = PhpSource::CLASSIFICATION_ACCESS;
        }

        $uses = PhpSource::uses($classes, $namespace);

        return implode("\n", [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace '.$namespace.';',
            '',
            ...($uses === [] ? [] : [...$uses, '']),
            '/**',
            ...array_map(static fn (string $line): string => rtrim(' * '.$line), $object->summary),
            ' */',
            'final readonly class '.$object->className,
            '{',
            '    /**',
            ...$docs,
            '     */',
            '    public function __construct(',
            ...$parameters,
            '    ) {}',
            ...($classified ? ['', ...self::visibleTo($object)] : []),
            '}',
        ])."\n";
    }

    /**
     * @return list<string>
     *
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput
     */
    private static function visibleTo(CodecObject $object): array
    {
        return [
            '    /**',
            '     * This object as a caller with $access may see it: every property classified above the',
            '     * access is Omitted (PRD 12.2).',
            '     */',
            '    public function visibleTo(ClassificationAccess $access): self',
            '    {',
            '        return new self(',
            ...array_map(
                static fn (CodecProperty $property): string => sprintf('            %s: %s,', $property->name, self::visible($property)),
                $object->properties,
            ),
            '        );',
            '    }',
        ];
    }

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidOutput for a list of lists of classified objects
     */
    private static function visible(CodecProperty $property): string
    {
        $value = '$this->'.$property->name;
        $nested = $property->value->object;
        $item = $property->value->item;

        if ($item instanceof CodecValue && $item->item instanceof CodecValue && $property->value->kind === CodecKind::List && self::innermost($item)?->classified() === true) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf('The property %s is a list of lists of classified objects, which a generated DTO cannot show to a caller.', $property->name));
        }

        if ($item instanceof CodecValue && $item->object instanceof CodecObject && $item->object->classified()) {
            $list = sprintf('array_map(static fn (%1$s $item): %1$s => $item->visibleTo($access), %2$s)', $item->object->className, $value);
            $value = $property->omittable() || $property->mayBeNull()
                ? sprintf('is_array(%s) ? %s : %s', $value, $list, $value)
                : $list;
        }

        if ($nested instanceof CodecObject && $nested->classified()) {
            $value = $property->omittable()
                ? sprintf('%s instanceof %s ? %s->visibleTo($access) : %s', $value, $nested->className, $value, $value)
                : $value.'->visibleTo($access)';
        }

        if (! $property->withheld() || ! $property->classification instanceof ClassificationAccess) {
            return $value;
        }

        return sprintf(
            '$access->allows(ClassificationAccess::%s) ? %s : Omitted::Field',
            $property->classification->name,
            str_contains($value, ' ? ') ? '('.$value.')' : $value,
        );
    }

    private static function innermost(CodecValue $value): ?CodecObject
    {
        while ($value->item instanceof CodecValue) {
            $value = $value->item;
        }

        return $value->object;
    }
}
