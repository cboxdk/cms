<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Validation\DecimalNumber;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\RuleValues;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use DateInterval;
use DateTimeImmutable;
use LogicException;
use Random\Randomizer;

/**
 * The field values of a seeded entry (GUARDRAILS 2.4, 4.3), for any type of the catalog: they are
 * drawn from the rules of the type's generated validator, so every value passes the kernel's
 * validation at the write and the release stage, and nothing here knows a type or a field.
 *
 * The seeder writes only the fields its actor may write: a field above the actor's classification
 * access is left out, and so is a field stored encrypted, whose key management comes with block B6
 * (PRD 12.2, 12.3). A type with such a field required cannot be seeded (SeedTypes).
 *
 * The values are skewed by the profile (PRD 23): an optional field holds a value in filledPercent of
 * the entries; a choice falls on the first options most often; numbers crowd towards their low
 * bound; dates and date-times crowd towards the profile's anchor and reach back spanDays; text and
 * lists vary in length. The same randomizer state gives the same values.
 */
#[Internal]
final readonly class FieldValueGenerator
{
    /** The domain of generated addresses and links, reserved for examples (RFC 2606). */
    public const string DOMAIN = 'example.org';

    /** How far past its low bound a number without a high bound reaches. */
    private const int NUMBER_REACH = 1000;

    /** The items of a list without max_items. */
    private const int LIST_ITEMS = 3;

    /** The blocks of a rich text value. */
    private const int BLOCKS = 3;

    /** The largest scaled decimal the generator works with, well inside a 64-bit integer. */
    private const int DECIMAL_UNITS = 10 ** 15;

    public function __construct(private SeedProfile $profile) {}

    /**
     * Whether an actor with the classification access may write the field: the seeder, which is no
     * agent, may read it (FieldDefinition::readableBy(), the rule the command pipeline holds every
     * writer to, WritableFields), and it is not stored encrypted.
     */
    public static function writable(FieldDefinition $field, ClassificationAccess $access): bool
    {
        return ! $field->encrypted && $field->readableBy($access, agent: false);
    }

    /**
     * Whether the field must hold a value at the stages the seeder writes and releases.
     */
    public static function required(FieldRules $field): bool
    {
        return $field->presence !== Presence::Optional;
    }

    public function fields(SeedableType $type, ClassificationAccess $access, Randomizer $random): FieldValues
    {
        $own = $this->top($type, null, $type->rules->fields, $access, $random);
        $extensions = [];

        foreach ($type->rules->extensions as $extension) {
            $fields = $this->top($type, $extension->namespace, $extension->fields, $access, $random);

            if (! $fields->isEmpty()) {
                $extensions[] = new ExtensionFields($extension->namespace, $fields);
            }
        }

        return new FieldValues($own, ...$extensions);
    }

    /**
     * The top-level fields of the owner or of one extender that the actor may write.
     *
     * @param  list<FieldRules>  $rules
     */
    private function top(SeedableType $type, ?FieldNamespace $namespace, array $rules, ClassificationAccess $access, Randomizer $random): FieldMap
    {
        $named = [];

        foreach ($rules as $field) {
            $definition = $type->definition->field($namespace, $field->handle);

            if (! $definition instanceof FieldDefinition || ! self::writable($definition, $access) || ! $this->present($field, $random)) {
                continue;
            }

            $named[] = new NamedValue($field->handle, $this->value($field, $random));
        }

        return new FieldMap(...$named);
    }

    /**
     * The fields of a group, which share their group's classification.
     *
     * @param  list<FieldRules>  $rules
     */
    private function nested(array $rules, Randomizer $random): FieldMap
    {
        $named = [];

        foreach ($rules as $field) {
            if ($this->present($field, $random)) {
                $named[] = new NamedValue($field->handle, $this->value($field, $random));
            }
        }

        return new FieldMap(...$named);
    }

    private function present(FieldRules $field, Randomizer $random): bool
    {
        return self::required($field) || $random->getInt(1, 100) <= $this->profile->filledPercent;
    }

    private function value(FieldRules $field, Randomizer $random): FieldValue
    {
        return match ($field->type) {
            RuleName::String => new TextValue($this->text($field, $random)),
            RuleName::Integer => new IntegerValue($this->integer($field, $random)),
            RuleName::Decimal => new DecimalValue($this->decimal($field, $random)),
            RuleName::Boolean => new BooleanValue($random->getInt(1, 100) <= 30),
            RuleName::Date => new DateValue($this->date($field, $random)),
            RuleName::Datetime => new DateTimeValue($this->datetime($field, $random)),
            RuleName::Object => new GroupValue($this->nested($field->fields, $random)),
            RuleName::List => $this->list($field, $random),
            RuleName::PortableText => $this->richText($field, $random),
            default => throw new LogicException(sprintf('The rule %s is not the type rule of a field.', $field->type->value)),
        };
    }

    private function text(FieldRules $field, Randomizer $random): string
    {
        $in = $field->rule(RuleName::In)->arguments ?? [];

        if ($in !== []) {
            return $in[new ZipfDistribution(count($in), $this->profile->valueSkew)->pick($random)];
        }

        $min = $field->rule(RuleName::MinLength)?->number() ?? 0;
        $max = $field->rule(RuleName::MaxLength)?->number();

        return match ($field->rule(RuleName::Format)?->arguments[0] ?? null) {
            'email' => sprintf('%s.%s@%s', SeedText::word($random), SeedText::word($random), self::DOMAIN),
            'url' => sprintf('https://%s/%s/%s', self::DOMAIN, SeedText::word($random), SeedText::word($random)),
            default => $max !== null && $max <= 255
                ? SeedText::words($random, 2, 8, $min, $max)
                : SeedText::words($random, 8, 60, $min, $max),
        };
    }

    private function integer(FieldRules $field, Randomizer $random): int
    {
        $min = RuleValues::integer($field->rule(RuleName::Min)?->arguments[0] ?? '');
        $max = RuleValues::integer($field->rule(RuleName::Max)?->arguments[0] ?? '');
        $low = $min ?? ($max === null ? 0 : min(0, $max - self::NUMBER_REACH));
        $high = $max ?? $low + self::NUMBER_REACH;

        return $this->skewed($low, $high, $random);
    }

    private function decimal(FieldRules $field, Randomizer $random): string
    {
        $arguments = $field->rule(RuleName::Decimal)->arguments ?? [];
        $precision = RuleValues::integer($arguments[0] ?? '') ?? 1;
        $scale = RuleValues::integer($arguments[1] ?? '') ?? 0;
        $limit = $precision >= 15 ? self::DECIMAL_UNITS : 10 ** $precision - 1;
        $unit = 10 ** min($scale, 15);
        $min = $this->units($field->rule(RuleName::Min)?->arguments[0] ?? null, $scale, $limit, true);
        $max = $this->units($field->rule(RuleName::Max)?->arguments[0] ?? null, $scale, $limit, false);
        $reach = min($limit, self::NUMBER_REACH * $unit);
        $low = max(-$limit, $min ?? ($max === null ? 0 : $max - $reach));
        $high = min($limit, $max ?? $low + $reach);
        $units = $this->skewed($low, $high, $random);
        $digits = str_pad((string) abs($units), $scale + 1, '0', STR_PAD_LEFT);

        return ($units < 0 ? '-' : '').($scale === 0 ? $digits : substr($digits, 0, -$scale).'.'.substr($digits, -$scale));
    }

    /**
     * A decimal bound as a whole number of the scale's units, rounded into the bound: up for a
     * minimum, down for a maximum. Null for no bound.
     */
    private function units(?string $bound, int $scale, int $limit, bool $minimum): ?int
    {
        $number = $bound === null ? null : DecimalNumber::parse($bound);

        if (! $number instanceof DecimalNumber) {
            return null;
        }

        $sign = $number->negative ? -1 : 1;

        if (strlen($number->whole) + $scale > 15) {
            return $sign * $limit;
        }

        $kept = substr(str_pad($number->fraction, $scale, '0'), 0, $scale);
        $units = (int) ($number->whole.$kept);
        $cut = strlen($number->fraction) > $scale && rtrim(substr($number->fraction, $scale), '0') !== '';

        if ($cut && ($minimum xor $number->negative)) {
            $units++;
        }

        return $sign * $units;
    }

    private function date(FieldRules $field, Randomizer $random): string
    {
        $day = $this->profile->anchor->sub(new DateInterval(sprintf('P%dD', $this->back($this->profile->spanDays, $random))))->format('Y-m-d');
        $min = $field->rule(RuleName::Min)?->arguments[0] ?? null;
        $max = $field->rule(RuleName::Max)?->arguments[0] ?? null;

        if ($min !== null && strcmp($day, $min) < 0) {
            return $min;
        }

        return $max !== null && strcmp($day, $max) > 0 ? $max : $day;
    }

    private function datetime(FieldRules $field, Randomizer $random): DateTimeImmutable
    {
        $instant = $this->profile->anchor->sub(new DateInterval(sprintf('PT%dS', $this->back($this->profile->spanDays * 86_400, $random))));
        $min = RuleValues::datetime($field->rule(RuleName::Min)?->arguments[0] ?? '');
        $max = RuleValues::datetime($field->rule(RuleName::Max)?->arguments[0] ?? '');

        if ($min instanceof DateTimeImmutable && $instant < $min) {
            return $min;
        }

        return $max instanceof DateTimeImmutable && $instant > $max ? $max : $instant;
    }

    /**
     * How far back from the anchor a date lies, 0 to $span, crowded towards 0 by the date skew.
     */
    private function back(int $span, Randomizer $random): int
    {
        return min($span, (int) floor($span * ($random->nextFloat() ** $this->profile->dateSkew)));
    }

    private function list(FieldRules $field, Randomizer $random): ListValue
    {
        $in = $field->rule(RuleName::ItemsIn)->arguments ?? [];
        $low = max(1, $field->rule(RuleName::MinItems)?->number() ?? 0);
        $high = $field->rule(RuleName::MaxItems)?->number() ?? max($low, self::LIST_ITEMS);

        if ($in !== []) {
            $high = min($high, count($in));
            $count = $this->skewed(min($low, $high), $high, $random);

            return $count < 1 ? new ListValue : new ListValue(...array_map(
                static fn (int $key): TextValue => new TextValue($in[$key]),
                $random->pickArrayKeys($in, $count),
            ));
        }

        $items = [];
        $count = $this->skewed(min($low, $high), $high, $random);

        for ($index = 0; $index < $count; $index++) {
            $items[] = new GroupValue($this->nested($field->fields, $random));
        }

        return new ListValue(...$items);
    }

    /**
     * Portable Text of one to three blocks of one span each, in the first style the field allows
     * ("normal" when it allows that or every style), without marks or links.
     */
    private function richText(FieldRules $field, Randomizer $random): ListValue
    {
        $styles = $field->rule(RuleName::Styles)?->arguments;
        $style = $styles === null || in_array('normal', $styles, true) ? 'normal' : ($styles[0] ?? null);
        $blocks = [];
        $count = $this->skewed(1, self::BLOCKS, $random);

        for ($index = 0; $index < $count; $index++) {
            $entries = [
                new MapEntry('_type', new TextValue('block')),
                new MapEntry('_key', new TextValue('b'.$index)),
                new MapEntry('children', new ListValue(new MapValue(
                    new MapEntry('_type', new TextValue('span')),
                    new MapEntry('_key', new TextValue('s0')),
                    new MapEntry('text', new TextValue(SeedText::words($random, 8, 40))),
                ))),
            ];

            if ($style !== null) {
                $entries[] = new MapEntry('style', new TextValue($style));
            }

            $blocks[] = new MapValue(...$entries);
        }

        return new ListValue(...$blocks);
    }

    /**
     * A whole number from $low to $high, crowded towards $low.
     */
    private function skewed(int $low, int $high, Randomizer $random): int
    {
        if ($high <= $low) {
            return $low;
        }

        $span = $high - $low;

        return $low + min($span, (int) floor(($span + 1) * ($random->nextFloat() ** 2)));
    }
}
