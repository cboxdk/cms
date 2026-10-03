<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

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
 * A test-only type, test:brief, as generated code would describe it, for the tests of what a
 * writer may set (PRD 2.31, 12.2): a public title, an internal `notes` open to agents, an internal
 * `contact` closed to agents, and a public group `place` with a `name` open to agents and a `code`
 * closed to them. Its history is full with stages draft-release, or audit-only with stages none
 * when a test asks for it. It is no content type of the kernel (GUARDRAILS 2.4); the tests hand it
 * to the fake catalog and validators.
 */
final readonly class BriefType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000003d2';

    public static function definition(History $history = History::Full): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:brief'),
            1,
            new TypeCapabilities($history, $history === History::Full ? Stages::DraftRelease : Stages::None, Localization::None, false),
            [],
            [
                new FieldDefinition(null, new FieldHandle('contact'), 'text', ClassificationAccess::Internal, false, false, false, false, false, new ColumnDefinition('contact', 'text', false, [])),
                new FieldDefinition(null, new FieldHandle('notes'), 'text', ClassificationAccess::Internal, true, false, false, false, false, new ColumnDefinition('notes', 'text', false, [])),
                new FieldDefinition(null, new FieldHandle('place'), 'group', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('place', 'jsonb', false, []), [
                    new FieldDefinition(null, new FieldHandle('code'), 'text', ClassificationAccess::Public, false, false, false, false, false, null),
                    new FieldDefinition(null, new FieldHandle('name'), 'text', ClassificationAccess::Public, true, false, false, false, false, null),
                ]),
                new FieldDefinition(null, new FieldHandle('title'), 'text', ClassificationAccess::Public, true, false, true, false, false, new ColumnDefinition('title', 'text', true, [])),
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
            new FieldRules(new FieldHandle('contact'), Presence::Optional, [new Rule(RuleName::String)]),
            new FieldRules(new FieldHandle('notes'), Presence::Optional, [new Rule(RuleName::String)]),
            new FieldRules(new FieldHandle('place'), Presence::Optional, [new Rule(RuleName::Object)], [
                new FieldRules(new FieldHandle('code'), Presence::Optional, [new Rule(RuleName::String)]),
                new FieldRules(new FieldHandle('name'), Presence::Optional, [new Rule(RuleName::String)]),
            ]),
            new FieldRules(new FieldHandle('title'), Presence::Required, [new Rule(RuleName::String)]),
        ]);
    }
}
