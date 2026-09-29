<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of a field type an addon contributes, `<namespace>:<handle>`, such as `acme:stars`
 * (PRD 13.1, 11.12). A blueprint file names it as a field's `type`. The handle is lowercase
 * snake_case without a double underscore; the namespace is the addon's, which AddonManifest
 * checks, so no addon takes another's field type or a name the core may take later.
 */
#[Experimental]
final readonly class ContributedFieldType
{
    private const string PATTERN = '/\A(?<namespace>[a-z][a-z0-9]{0,19}):(?<handle>[a-z][a-z0-9]*(?:_[a-z0-9]+)*)\z/';

    public string $namespace;

    public string $handle;

    /**
     * @throws InvalidAddonManifest when it is not `<namespace>:<handle>`
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1 || strlen($parts['handle']) > 63) {
            throw InvalidAddonManifest::because(sprintf(
                'The field type "%s" is not <namespace>:<handle>, such as "acme:stars": the addon\'s namespace, a colon and a lowercase snake_case handle of at most 63 characters (PRD 13.1).',
                $value,
            ));
        }

        $this->namespace = $parts['namespace'];
        $this->handle = $parts['handle'];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
