<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\FieldFallback;
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
 * A test-only type with localization variants (PRD 10.1), as a TypeCatalog and TypeValidators
 * would give it: `test:localized_probe` with a required translatable text `title` of at most 40
 * characters that falls back on its own, an optional translatable long text `teaser`, and an
 * optional integer `rank` in the shared variant. The kernel knows it only through the fakes of
 * those contracts (GUARDRAILS 2.4).
 */
final readonly class LocalizedProbeType implements TypeValidator
{
    public const string ID = '01936f5e-8a2b-7c3d-9e4f-0000000000d2';

    public static function definition(): TypeDefinition
    {
        return new TypeDefinition(
            TypeId::fromString(self::ID),
            new TypeName('test:localized_probe'),
            1,
            new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::Variants, false),
            [],
            [
                new FieldDefinition(null, new FieldHandle('rank'), 'integer', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('rank', 'bigint', false, [])),
                new FieldDefinition(null, new FieldHandle('teaser'), 'long_text', ClassificationAccess::Public, true, false, false, false, false, new ColumnDefinition('teaser', 'text', false, []), translatable: true),
                new FieldDefinition(null, new FieldHandle('title'), 'text', ClassificationAccess::Public, true, false, true, false, false, new ColumnDefinition('title', 'text', true, []), translatable: true, fallback: FieldFallback::Field),
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
            new FieldRules(new FieldHandle('title'), Presence::Required, [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['40'])], [], true),
            new FieldRules(new FieldHandle('teaser'), Presence::Optional, [new Rule(RuleName::String)], [], true),
            new FieldRules(new FieldHandle('rank'), Presence::Optional, [new Rule(RuleName::Integer)]),
        ]);
    }
}
