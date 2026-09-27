<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

/**
 * GUARDRAILS 2.3: addons may not use what the core marks #[Internal]. The three Internal*
 * extensions ask this class whether a use is allowed.
 *
 * Code inside the Cbox\Cms root namespace may use internals, test code included. Code in any
 * other namespace, an addon, the app template or the global namespace, may not. PHPStan's
 * restricted usage rules find the uses: class names in new, static calls, constants,
 * instanceof, catch, extends, implements, trait use, attributes, native types and PHPDoc
 * types, and calls to methods. InternalUseIgnoreErrorExtension and InternalUseRule then make
 * the errors non-ignorable, except where a comment names the identifier.
 */
#[Internal]
final class InternalUse
{
    public const string IDENTIFIER = 'cboxCms.internalUse';

    /** The root namespace of the core packages. Only code inside it may use internals. */
    public const string CORE = 'Cbox\Cms';

    public static function isCore(?string $namespace): bool
    {
        return $namespace !== null
            && (strcasecmp($namespace, self::CORE) === 0 || str_starts_with(strtolower($namespace), strtolower(self::CORE.'\\')));
    }

    /**
     * @param  list<AttributeReflection>  $attributes
     */
    public static function isMarked(array $attributes): bool
    {
        return array_any($attributes, fn (AttributeReflection $attribute): bool => strcasecmp($attribute->getName(), Internal::class) === 0);
    }

    /**
     * The error for a use of an internal class or member, or null when the scope is inside
     * the core.
     *
     * @param  string  $use  what PHPStan says is used, for example "Instantiation of internal class Foo."
     */
    public static function restriction(string $use, Scope $scope): ?RestrictedUsage
    {
        if (self::isCore($scope->getNamespace())) {
            return null;
        }

        return RestrictedUsage::create(
            sprintf('%s It is marked #[Internal], and only code in the %s namespace may use it (GUARDRAILS 2.3). Use a #[Stable] or #[Experimental] contract instead.', $use, self::CORE),
            self::IDENTIFIER,
        );
    }
}
