<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * Who owns a definition, and the namespace of its extension fields (PRD 11.12): `app` for the
 * application, or the name of a module or addon from its manifest (PRD 13.1). A name is a lowercase
 * letter followed by at most 19 lowercase letters and digits, without an underscore. `ext` is
 * reserved for the extension fields themselves.
 */
#[Internal]
final readonly class Owner
{
    public const string APP = 'app';

    public const string PATTERN = '/\A[a-z][a-z0-9]{0,19}\z/';

    /**
     * @throws GenerationFailed with GenerateErrorCode::InvalidConfig
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || $value === 'ext') {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                '"%s" is not an owner. An owner is "app" or the name of a module or addon: a lowercase letter followed by at most 19 lowercase letters and digits, and not "ext".',
                $value,
            ));
        }
    }

    public static function app(): self
    {
        return new self(self::APP);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
