<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldNamespace;

/**
 * The name of an addon, which is the namespace of its extension fields, its field types and its
 * own types (PRD 13.1, 11.12): a lowercase letter followed by at most 19 lowercase letters and
 * digits, without an underscore, such as `acme`. It is unique in the installation; cms:build
 * refuses two addons with one name. `app` and `ext` are reserved and throw
 * ReservedAddonNamespace; any other string that does not fit throws InvalidAddonManifest.
 */
#[Experimental]
final readonly class AddonNamespace
{
    public const string PATTERN = '/\A[a-z][a-z0-9]{0,19}\z/';

    /**
     * The namespaces no addon may take (PRD 11.12).
     *
     * @var list<string>
     */
    public const array RESERVED = ['app', 'ext'];

    /**
     * @throws ReservedAddonNamespace for `app` and `ext`
     * @throws InvalidAddonManifest for anything else that is not a namespace
     */
    public function __construct(public string $value)
    {
        if (in_array($value, self::RESERVED, true)) {
            throw ReservedAddonNamespace::named($value);
        }

        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidAddonManifest::because(sprintf(
                'The addon namespace "%s" is not a lowercase letter followed by at most 19 lowercase letters and digits, such as "acme" (PRD 13.1).',
                $value,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The namespace of the addon's extension fields, `ext.<namespace>` (PRD 11.12).
     */
    public function fieldNamespace(): FieldNamespace
    {
        return new FieldNamespace($this->value);
    }
}
