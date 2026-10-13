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
 * A test-only type with relations (PRD 5.11), as a TypeCatalog and TypeValidators would give it:
 * `test:linked` with `next`, a relation to one `test:linked`, `cited`, a relation to up to three
 * entries of `test:linked` or `test:probe`, the group `meta` whose `source` names one
 * `test:probe`, and the extender `probe`'s `see`, a relation to one `test:linked`.
 */
final readonly class LinkedProbeType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000000d3';

    public static function definition(): TypeDefinition
    {
        $public = ClassificationAccess::Public;

        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:linked'),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
            [new ExtensionVersion(new FieldNamespace(ProbeType::EXTENDER), 1)],
            [
                new FieldDefinition(new FieldNamespace(ProbeType::EXTENDER), new FieldHandle('see'), 'relation', $public, true, false, false, false, false, new ColumnDefinition('ext__probe__see', 'uuid', false, [])),
                new FieldDefinition(null, new FieldHandle('cited'), 'relation', $public, true, false, false, false, false, new ColumnDefinition('cited', 'jsonb', false, [])),
                new FieldDefinition(null, new FieldHandle('meta'), 'group', $public, true, false, false, false, false, new ColumnDefinition('meta', 'jsonb', false, []), [
                    new FieldDefinition(null, new FieldHandle('source'), 'relation', $public, true, false, false, false, false, null),
                ]),
                new FieldDefinition(null, new FieldHandle('next'), 'relation', $public, true, false, false, false, false, new ColumnDefinition('next', 'uuid', false, [])),
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
            new FieldRules(new FieldHandle('next'), Presence::Optional, [new Rule(RuleName::Relation), new Rule(RuleName::Targets, ['test:linked'])]),
            new FieldRules(new FieldHandle('cited'), Presence::Optional, [new Rule(RuleName::Relations), new Rule(RuleName::Targets, ['test:linked', 'test:probe']), new Rule(RuleName::MaxItems, ['3'])]),
            new FieldRules(new FieldHandle('meta'), Presence::Optional, [new Rule(RuleName::Object)], [
                new FieldRules(new FieldHandle('source'), Presence::Optional, [new Rule(RuleName::Relation), new Rule(RuleName::Targets, ['test:probe'])]),
            ]),
        ], [
            new ExtensionRules(new FieldNamespace(ProbeType::EXTENDER), [
                new FieldRules(new FieldHandle('see'), Presence::Optional, [new Rule(RuleName::Relation), new Rule(RuleName::Targets, ['test:linked'])]),
            ]),
        ]);
    }
}
