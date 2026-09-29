<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

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
 * A test-only type for the command pipeline, as a TypeCatalog and TypeValidators would give it:
 * `test:probe` with a required text `label` of at most 20 characters, an optional integer `rank`
 * and an optional confidential text `memo`, and the extender `probe`'s optional texts `tag`,
 * public, and `code`, confidential. The kernel knows it only through the fakes of those contracts
 * (GUARDRAILS 2.4).
 */
final readonly class ProbeType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000000d1';

    /** The namespace of the extender's fields. */
    public const string EXTENDER = 'probe';

    public static function definition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:probe'),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
            [new ExtensionVersion(new FieldNamespace(self::EXTENDER), 1)],
            [
                new FieldDefinition(new FieldNamespace(self::EXTENDER), new FieldHandle('code'), 'text', ClassificationAccess::Confidential, true, false, false, false, false, new ColumnDefinition('ext__probe__code', 'text', false, [])),
                new FieldDefinition(new FieldNamespace(self::EXTENDER), new FieldHandle('tag'), 'text', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('ext__probe__tag', 'text', false, [])),
                new FieldDefinition(null, new FieldHandle('memo'), 'text', ClassificationAccess::Confidential, true, false, false, false, false, new ColumnDefinition('memo', 'text', false, [])),
                new FieldDefinition(null, new FieldHandle('label'), 'text', ClassificationAccess::Public, true, false, true, false, false, new ColumnDefinition('label', 'text', true, [])),
                new FieldDefinition(null, new FieldHandle('rank'), 'integer', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('rank', 'bigint', false, [])),
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
        return new TypeRules([
            new FieldRules(new FieldHandle('label'), Presence::Required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['20'])]),
            new FieldRules(new FieldHandle('rank'), Presence::Optional, [new Rule(RuleName::Integer)]),
            new FieldRules(new FieldHandle('memo'), Presence::Optional, [new Rule(RuleName::String)]),
        ], [
            new ExtensionRules(new FieldNamespace(self::EXTENDER), [
                new FieldRules(new FieldHandle('code'), Presence::Optional, [new Rule(RuleName::String)]),
                new FieldRules(new FieldHandle('tag'), Presence::Optional, [new Rule(RuleName::String)]),
            ]),
        ]);
    }
}
