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
 * A test type whose drafts are released: full history, stages draft-release, a public `title` that
 * is required and a public `summary` that is required only when a revision is released.
 */
final readonly class DraftNoteType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000047d2';

    public static function definition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:draft_note'),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
            [],
            [
                new FieldDefinition(null, new FieldHandle('summary'), 'text', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('summary', 'text', false, [])),
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
            new FieldRules(new FieldHandle('summary'), Presence::RequiredOnRelease, [new Rule(RuleName::String)]),
            new FieldRules(new FieldHandle('title'), Presence::Required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['80'])]),
        ]);
    }
}
