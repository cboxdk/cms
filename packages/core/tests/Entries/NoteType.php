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
 * A test-only type, test:note, as generated code would describe it, for the tests of the entry
 * actions without a database: a required title of at most 40 characters, and a secret, classified
 * confidential and so stored encrypted (PRD 12.2). It is no content type of the kernel (GUARDRAILS
 * 2.4); the tests hand it to the fake catalog and validators.
 */
final readonly class NoteType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000003d1';

    public static function definition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:note'),
            3,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
            [],
            [
                new FieldDefinition(null, new FieldHandle('secret'), 'text', ClassificationAccess::Confidential, false, true, false, false, false, new ColumnDefinition('secret', 'bytea', false, [])),
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
            new FieldRules(new FieldHandle('secret'), Presence::Optional, [new Rule(RuleName::String)]),
            new FieldRules(new FieldHandle('title'), Presence::Required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['40'])]),
        ]);
    }
}
