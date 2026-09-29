<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Validation;

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
use Cbox\Cms\Testkit\Tests\Schema\SampleTypes;
use Override;

/**
 * A hand-written validator of one of the SampleTypes, as cms:generate would write it: the type's
 * id and the rules of its text fields.
 */
final readonly class SampleValidator implements TypeValidator
{
    private function __construct(
        private string $type,
        private TypeRules $rules,
    ) {}

    /**
     * The validator of SampleTypes::note(): a required title, and the app's title under its namespace.
     */
    public static function note(): self
    {
        return new self(SampleTypes::NOTE_ID, new TypeRules(
            [self::text('title', Presence::Required)],
            [new ExtensionRules(new FieldNamespace('app'), [self::text('title', Presence::Optional)])],
        ));
    }

    /**
     * The validator of SampleTypes::appNote(): an optional body.
     */
    public static function appNote(): self
    {
        return new self(SampleTypes::APP_NOTE_ID, new TypeRules([self::text('body', Presence::Optional)]));
    }

    #[Override]
    public function type(): TypeId
    {
        return TypeId::fromString($this->type);
    }

    #[Override]
    public function rules(): TypeRules
    {
        return $this->rules;
    }

    private static function text(string $handle, Presence $presence): FieldRules
    {
        return new FieldRules(new FieldHandle($handle), $presence, [new Rule(RuleName::String)]);
    }
}
