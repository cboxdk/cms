<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Rules\RestrictedUsage\RestrictedClassConstantUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

/**
 * Reports an access outside the core to a class constant or enum case that is marked
 * #[Internal] or is declared by an #[Internal] class (GUARDRAILS 2.3). The ::class name of
 * an internal class is reported by InternalClassNameUsageExtension.
 */
#[Internal]
final class InternalClassConstantUsageExtension implements RestrictedClassConstantUsageExtension
{
    public function isRestrictedClassConstantUsage(ClassConstantReflection $constantReflection, Scope $scope): ?RestrictedUsage
    {
        $class = $constantReflection->getDeclaringClass();

        if (InternalUse::isMarked($constantReflection->getAttributes())) {
            $use = sprintf('Access to internal constant %s::%s.', $class->getDisplayName(), $constantReflection->getName());
        } elseif (InternalUse::isMarked($class->getAttributes())) {
            $use = sprintf('Access to constant %s of internal class %s.', $constantReflection->getName(), $class->getDisplayName());
        } else {
            return null;
        }

        return InternalUse::restriction($use, $scope);
    }
}
