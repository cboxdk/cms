<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use LogicException;

/**
 * A field type was registered under a name its contributor may not use (PRD 11.12, 13.1): outside
 * the contributor's own namespace, in the reserved namespace `app`, or without a namespace by
 * another contributor than the core. The registry is wired in code, so this is a wiring error and
 * never the fault of a blueprint file.
 */
#[Internal]
final class InvalidFieldTypeName extends LogicException
{
    public static function withoutNamespace(FieldTypeContributor $contributor): self
    {
        return new self(sprintf(
            '%s registers field types without a namespace. Only the core\'s %s does; another contributor names the module or addon it belongs to as its owner and each of its field types <namespace>:<handle> in that namespace, such as "acme:colour" (PRD 13.1).',
            $contributor::class,
            CoreFieldTypes::class,
        ));
    }

    public static function reservedNamespace(FieldTypeContributor $contributor, Owner $owner): self
    {
        return new self(sprintf(
            '%s registers field types in the namespace "%s", which is reserved for the application and is never the name of a module or addon (PRD 11.12, 13.1). A contributor names its field types in the namespace of the module or addon it belongs to.',
            $contributor::class,
            $owner->value,
        ));
    }

    public static function outsideNamespace(string $name, FieldTypeContributor $contributor, Owner $owner): self
    {
        return new self(sprintf(
            'The field type "%s" is registered by %s, whose namespace is "%s". A contributor names each of its field types %s:<handle> with a handle in lowercase snake_case, such as "%s:colour", so it can never take another contributor\'s name or a later core field type\'s (PRD 13.1).',
            $name,
            $contributor::class,
            $owner->value,
            $owner->value,
            $owner->value,
        ));
    }

    public static function coreName(string $name): self
    {
        return new self(sprintf(
            'The core field type "%s" is not a handle. A core field type has no namespace, and its name is lowercase snake_case, such as "long_text", so it can never take the name of a field type that a module or addon registers as <namespace>:<handle>.',
            $name,
        ));
    }
}
