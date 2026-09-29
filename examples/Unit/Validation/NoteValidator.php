<?php

declare(strict_types=1);

namespace Examples\Unit\Validation;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Override;

/**
 * The validator of a note type, in the form cms:generate writes one to app/Cms/Generated/Validators:
 * a required title of at most 80 characters, optional tags from a fixed list, and a field the
 * application adds in its namespace app, which a release requires.
 */
final readonly class NoteValidator implements TypeValidator
{
    #[Override]
    public function type(): TypeId
    {
        return TypeId::fromString('0199b1c2-3d4e-7f50-8a61-7b8c9d0e1f2a');
    }

    #[Override]
    public function rules(): TypeRules
    {
        return new TypeRules(
            [
                new FieldRules(
                    new FieldHandle('tags'),
                    Presence::Optional,
                    [
                        new Rule(RuleName::List),
                        new Rule(RuleName::Distinct),
                        new Rule(RuleName::ItemsIn, ['idea', 'task']),
                        new Rule(RuleName::MaxItems, ['2']),
                    ],
                ),
                new FieldRules(
                    new FieldHandle('title'),
                    Presence::Required,
                    [
                        new Rule(RuleName::String),
                        new Rule(RuleName::MaxLength, ['80']),
                    ],
                ),
            ],
            [
                new ExtensionRules(new FieldNamespace('app'), [
                    new FieldRules(
                        new FieldHandle('review_by'),
                        Presence::RequiredOnRelease,
                        [
                            new Rule(RuleName::Date),
                        ],
                    ),
                ]),
            ],
        );
    }
}
