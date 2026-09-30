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
 * A test type the seeder cannot always write: its field `code` is required and classified
 * internal, so an actor with public classification access cannot seed it; `label` is public and
 * optional. It keeps no history and has no stages.
 */
final readonly class LockedType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000047d1';

    public static function definition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:locked'),
            1,
            new TypeCapabilities(History::None, Stages::None, Localization::None, false),
            [],
            [
                new FieldDefinition(null, new FieldHandle('code'), 'text', ClassificationAccess::Internal, true, false, true, false, false, new ColumnDefinition('code', 'text', true, [])),
                new FieldDefinition(null, new FieldHandle('label'), 'text', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('label', 'text', false, [])),
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
            new FieldRules(new FieldHandle('code'), Presence::Required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['12'])]),
            new FieldRules(new FieldHandle('label'), Presence::Optional, [new Rule(RuleName::String)]),
        ]);
    }
}
