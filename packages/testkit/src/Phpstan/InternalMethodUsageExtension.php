<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Rules\RestrictedUsage\RestrictedMethodUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

/**
 * Reports a call outside the core to a method that is marked #[Internal] or is declared by
 * an #[Internal] class (GUARDRAILS 2.3). PHPStan calls it for instance and static calls.
 */
#[Internal]
final class InternalMethodUsageExtension implements RestrictedMethodUsageExtension
{
    public function isRestrictedMethodUsage(ExtendedMethodReflection $methodReflection, Scope $scope): ?RestrictedUsage
    {
        $class = $methodReflection->getDeclaringClass();

        if (InternalUse::isMarked($methodReflection->getAttributes())) {
            $use = sprintf('Call to internal method %s::%s().', $class->getDisplayName(), $methodReflection->getName());
        } elseif (InternalUse::isMarked($class->getAttributes())) {
            $use = sprintf('Call to method %s() of internal class %s.', $methodReflection->getName(), $class->getDisplayName());
        } else {
            return null;
        }

        return InternalUse::restriction($use, $scope);
    }
}
