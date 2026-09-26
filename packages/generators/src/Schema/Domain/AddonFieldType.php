<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * A field type that an addon contributes, written `<namespace>:<handle>` such as `acme:colour`
 * (PRD 13.1, blueprint schema v1). The blueprint schema checks only the form; whether a registered
 * contributor provides the type, and whether the field's options suit it, is decided against the
 * contributions.
 */
#[Internal]
final readonly class AddonFieldType
{
    /** The pattern of `$defs/field/properties/type` in blueprint.v1.json for an addon's field type. */
    public const string PATTERN = '/\A[a-z][a-z0-9]{0,19}:[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    public string $namespace;

    public string $handle;

    /**
     * @throws GenerationFailed with GenerateErrorCode::SchemaInvalid
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaInvalid, sprintf(
                '"%s" is not an addon field type. Write it as <namespace>:<handle>, such as "acme:colour".',
                $value,
            ));
        }

        [$this->namespace, $this->handle] = explode(':', $value, 2);
    }
}
