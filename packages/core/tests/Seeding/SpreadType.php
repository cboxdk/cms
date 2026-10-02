<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\ExtensionVersion;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Override;

/**
 * A test type with the shapes RulesType leaves out, so the seeder's values can be held to a golden
 * file: decimals without bounds, bounded on one side and with more whole digits than the seeder
 * works with, integers without bounds and bounded above, a boolean, dates without bounds, short
 * and long text, choices and groups without a count, rich text in the normal style and without a
 * styles rule, optional fields, a field above the seeder's access, one stored encrypted, and an
 * extension with a fixed and an optional field.
 */
final readonly class SpreadType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000047d7';

    public const string NAMESPACE = 'acme';

    /** @var list<string> the owner's fields, sorted */
    public const array HANDLES = ['after', 'bare', 'before', 'big', 'cents', 'edge', 'essay', 'flag', 'free', 'locked', 'longish', 'lower', 'maybe', 'moment', 'nano', 'narrow', 'notes', 'pinned', 'ratio', 'secret', 'short', 'some', 'story', 'tags', 'title', 'top', 'when'];

    /** @var list<string> the extension's fields, sorted */
    public const array EXTENSION_HANDLES = ['extra', 'skip'];

    public static function definition(): TypeDefinition
    {
        $namespace = new FieldNamespace(self::NAMESPACE);
        $field = static fn (?FieldNamespace $in, string $handle): FieldDefinition => new FieldDefinition(
            $in,
            new FieldHandle($handle),
            'text',
            $handle === 'secret' ? ClassificationAccess::Confidential : ClassificationAccess::Public,
            true,
            $handle === 'locked',
            false,
            false,
            false,
            new ColumnDefinition($in instanceof FieldNamespace ? 'ext__'.self::NAMESPACE.'__'.$handle : $handle, $handle === 'locked' ? 'bytea' : 'text', false, []),
        );

        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:spread'),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
            [new ExtensionVersion($namespace, 1)],
            [
                ...array_map(static fn (string $handle): FieldDefinition => $field(null, $handle), self::HANDLES),
                ...array_map(static fn (string $handle): FieldDefinition => $field($namespace, $handle), self::EXTENSION_HANDLES),
            ],
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
        $optional = Presence::Optional;

        return new TypeRules([
            new FieldRules(new FieldHandle('after'), $required, [new Rule(RuleName::Datetime), new Rule(RuleName::Min, ['2025-06-01T00:00:00Z'])]),
            new FieldRules(new FieldHandle('bare'), $required, [new Rule(RuleName::PortableText)]),
            new FieldRules(new FieldHandle('before'), $required, [new Rule(RuleName::Datetime), new Rule(RuleName::Max, ['2025-06-01T00:00:00Z'])]),
            new FieldRules(new FieldHandle('big'), $required, [new Rule(RuleName::Decimal, ['18', '2']), new Rule(RuleName::Min, ['-1234567890123456.5'])]),
            new FieldRules(new FieldHandle('cents'), $required, [new Rule(RuleName::Decimal, ['4', '2'])]),
            new FieldRules(new FieldHandle('edge'), $required, [new Rule(RuleName::Decimal, ['5', '2']), new Rule(RuleName::Min, ['0.123'])]),
            new FieldRules(new FieldHandle('essay'), $required, [new Rule(RuleName::String)]),
            new FieldRules(new FieldHandle('flag'), $required, [new Rule(RuleName::Boolean)]),
            new FieldRules(new FieldHandle('free'), $required, [new Rule(RuleName::Integer)]),
            new FieldRules(new FieldHandle('locked'), $optional, [new Rule(RuleName::String)]),
            new FieldRules(new FieldHandle('longish'), $required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['256'])]),
            new FieldRules(new FieldHandle('lower'), $required, [new Rule(RuleName::Decimal, ['6', '3']), new Rule(RuleName::Min, ['-2.0005'])]),
            new FieldRules(new FieldHandle('maybe'), $optional, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['40'])]),
            new FieldRules(new FieldHandle('moment'), $required, [new Rule(RuleName::Datetime)]),
            new FieldRules(new FieldHandle('nano'), $required, [new Rule(RuleName::Decimal, ['3', '0'])]),
            new FieldRules(new FieldHandle('narrow'), $required, [new Rule(RuleName::Decimal, ['5', '2']), new Rule(RuleName::Min, ['1.2000']), new Rule(RuleName::Max, ['1.2300'])]),
            new FieldRules(new FieldHandle('notes'), $required, [new Rule(RuleName::List)], [
                new FieldRules(new FieldHandle('label'), $required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['12'])]),
                new FieldRules(new FieldHandle('weight'), $optional, [new Rule(RuleName::Integer), new Rule(RuleName::Min, ['1']), new Rule(RuleName::Max, ['9'])]),
            ]),
            new FieldRules(new FieldHandle('pinned'), $required, [new Rule(RuleName::Date), new Rule(RuleName::Min, ['2026-12-30']), new Rule(RuleName::Max, ['2026-12-30'])]),
            new FieldRules(new FieldHandle('ratio'), $required, [new Rule(RuleName::Decimal, ['6', '3']), new Rule(RuleName::Max, ['5.0004'])]),
            new FieldRules(new FieldHandle('secret'), $optional, [new Rule(RuleName::String)]),
            new FieldRules(new FieldHandle('short'), $required, [new Rule(RuleName::String), new Rule(RuleName::MinLength, ['300'])]),
            new FieldRules(new FieldHandle('some'), $required, [new Rule(RuleName::List), new Rule(RuleName::ItemsIn, ['p', 'q', 'r']), new Rule(RuleName::MinItems, ['2'])]),
            new FieldRules(new FieldHandle('story'), $required, [new Rule(RuleName::PortableText), new Rule(RuleName::Styles, ['h1', 'normal'])]),
            new FieldRules(new FieldHandle('tags'), $required, [new Rule(RuleName::List), new Rule(RuleName::ItemsIn, ['a', 'b', 'c', 'd', 'e'])]),
            new FieldRules(new FieldHandle('title'), $required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['255'])]),
            new FieldRules(new FieldHandle('top'), $required, [new Rule(RuleName::Integer), new Rule(RuleName::Max, ['50'])]),
            new FieldRules(new FieldHandle('when'), $required, [new Rule(RuleName::Date)]),
        ], [
            new ExtensionRules(new FieldNamespace(self::NAMESPACE), [
                new FieldRules(new FieldHandle('extra'), $required, [new Rule(RuleName::Integer), new Rule(RuleName::Min, ['3']), new Rule(RuleName::Max, ['3'])]),
                new FieldRules(new FieldHandle('skip'), $optional, [new Rule(RuleName::Boolean)]),
            ]),
        ]);
    }
}
