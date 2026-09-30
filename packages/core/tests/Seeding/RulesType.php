<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Override;

/**
 * A test type whose required fields have the bounds and formats the seeder must round into: decimal
 * bounds with more digits than the scale, on both sides of zero, integers bounded on one side,
 * lengths, formats, choices, lists of choices and of groups of a fixed size, rich text that allows
 * one style or none, and date and date-time bounds that the anchor lies outside of.
 */
final readonly class RulesType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000047d3';

    /** @var list<string> the handles of its fields, sorted */
    public const array HANDLES = ['amount', 'body', 'code', 'contact', 'count', 'day', 'debt', 'floor', 'link', 'mood', 'picks', 'plain', 'rows', 'stamp'];

    public static function definition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:rules'),
            1,
            new TypeCapabilities(History::None, Stages::None, Localization::None, false),
            [],
            array_map(
                static fn (string $handle): FieldDefinition => new FieldDefinition(null, new FieldHandle($handle), 'text', ClassificationAccess::Public, true, false, true, false, false, new ColumnDefinition($handle, 'text', true, [])),
                self::HANDLES,
            ),
        );
    }

    #[Override]
    public function type(): TypeId
    {
        return TypeId::fromString(self::ID);
    }

    #[Override]
    public function rules(): TypeRules
    {
        $required = Presence::Required;

        return new TypeRules([
            new FieldRules(new FieldHandle('amount'), $required, [new Rule(RuleName::Decimal, ['6', '2']), new Rule(RuleName::Min, ['0.005']), new Rule(RuleName::Max, ['9.999'])]),
            new FieldRules(new FieldHandle('body'), $required, [new Rule(RuleName::PortableText), new Rule(RuleName::Styles, ['h2']), new Rule(RuleName::Marks), new Rule(RuleName::Lists), new Rule(RuleName::Links)]),
            new FieldRules(new FieldHandle('code'), $required, [new Rule(RuleName::String), new Rule(RuleName::MinLength, ['5']), new Rule(RuleName::MaxLength, ['8'])]),
            new FieldRules(new FieldHandle('contact'), $required, [new Rule(RuleName::String), new Rule(RuleName::Format, ['email'])]),
            new FieldRules(new FieldHandle('count'), $required, [new Rule(RuleName::Integer), new Rule(RuleName::Max, ['-5'])]),
            new FieldRules(new FieldHandle('day'), $required, [new Rule(RuleName::Date), new Rule(RuleName::Max, ['2020-01-01'])]),
            new FieldRules(new FieldHandle('debt'), $required, [new Rule(RuleName::Decimal, ['5', '1']), new Rule(RuleName::Min, ['-50.55']), new Rule(RuleName::Max, ['-0.04'])]),
            new FieldRules(new FieldHandle('floor'), $required, [new Rule(RuleName::Integer), new Rule(RuleName::Min, ['10'])]),
            new FieldRules(new FieldHandle('link'), $required, [new Rule(RuleName::String), new Rule(RuleName::Format, ['url'])]),
            new FieldRules(new FieldHandle('mood'), $required, [new Rule(RuleName::String), new Rule(RuleName::In, ['calm', 'bright', 'grey'])]),
            new FieldRules(new FieldHandle('picks'), $required, [new Rule(RuleName::List), new Rule(RuleName::Distinct), new Rule(RuleName::ItemsIn, ['w', 'x', 'y', 'z']), new Rule(RuleName::MinItems, ['2']), new Rule(RuleName::MaxItems, ['2'])]),
            new FieldRules(new FieldHandle('plain'), $required, [new Rule(RuleName::PortableText), new Rule(RuleName::Styles)]),
            new FieldRules(new FieldHandle('rows'), $required, [new Rule(RuleName::List), new Rule(RuleName::MinItems, ['2']), new Rule(RuleName::MaxItems, ['2'])], [
                new FieldRules(new FieldHandle('label'), $required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['20'])]),
                new FieldRules(new FieldHandle('note'), Presence::Optional, [new Rule(RuleName::String)]),
            ]),
            new FieldRules(new FieldHandle('stamp'), $required, [new Rule(RuleName::Datetime), new Rule(RuleName::Min, ['2026-06-01T00:00:00Z']), new Rule(RuleName::Max, ['2026-12-31T00:00:00Z'])]),
        ]);
    }
}
