<?php

declare(strict_types=1);

namespace Examples\Unit\Validation;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Validation\Condition;
use Cbox\Cms\Contracts\Validation\FieldReference;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Override;

/**
 * The validator of a link type with conditional fields, in the form cms:generate writes one: a
 * target, a URL required while the target is external and cleared when it is hidden, and a group
 * whose medium is shown while its campaign has a value and the link's target, one group up, is
 * external, and keeps its value when it is hidden.
 */
final readonly class LinkValidator implements TypeValidator
{
    #[Override]
    public function type(): TypeId
    {
        return TypeId::fromString('0199b1c2-3d4e-7f50-8a61-7b8c9d0e1f3b');
    }

    #[Override]
    public function rules(): TypeRules
    {
        return new TypeRules([
            new FieldRules(
                new FieldHandle('target'),
                Presence::Required,
                [
                    new Rule(RuleName::String),
                    new Rule(RuleName::In, ['page', 'external']),
                ],
            ),
            new FieldRules(
                new FieldHandle('tracking'),
                Presence::Optional,
                [
                    new Rule(RuleName::Object),
                ],
                [
                    new FieldRules(
                        new FieldHandle('campaign'),
                        Presence::Optional,
                        [
                            new Rule(RuleName::String),
                        ],
                    ),
                    new FieldRules(
                        new FieldHandle('medium'),
                        Presence::Optional,
                        [
                            new Rule(RuleName::String),
                            new Rule(RuleName::MaxLength, ['20']),
                        ],
                        when: Condition::all([
                            Condition::notNull(new FieldReference(0, new FieldHandle('campaign'))),
                            Condition::eq(new FieldReference(1, new FieldHandle('target')), 'external'),
                        ]),
                        whenHidden: WhenHidden::Keep,
                    ),
                ],
            ),
            new FieldRules(
                new FieldHandle('url'),
                Presence::Required,
                [
                    new Rule(RuleName::String),
                    new Rule(RuleName::Format, ['url']),
                ],
                when: Condition::eq(new FieldReference(0, new FieldHandle('target')), 'external'),
                whenHidden: WhenHidden::Clear,
            ),
        ]);
    }
}
