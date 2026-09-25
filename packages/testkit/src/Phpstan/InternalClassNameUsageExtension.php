<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassConstantReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\ClassNameUsageLocation;
use PHPStan\Rules\RestrictedUsage\RestrictedClassNameUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

/**
 * Reports the name of an #[Internal] class used outside the core (GUARDRAILS 2.3): in new,
 * static calls, constants and ::class, instanceof, catch, extends, implements, trait use,
 * attributes, native types and PHPDoc types.
 *
 * A static call to an internal method and an access to an internal constant are left to
 * InternalMethodUsageExtension and InternalClassConstantUsageExtension, so each use is
 * reported once.
 */
#[Internal]
final class InternalClassNameUsageExtension implements RestrictedClassNameUsageExtension
{
    public function isRestrictedClassNameUsage(ClassReflection $classReflection, Scope $scope, ClassNameUsageLocation $location): ?RestrictedUsage
    {
        if (! InternalUse::isMarked($classReflection->getAttributes()) || $this->isReportedAsMember($location)) {
            return null;
        }

        $class = sprintf('internal class %s', $classReflection->getDisplayName());

        // PHPStan reports Foo::class as a constant access without a constant.
        $use = $location->value === ClassNameUsageLocation::CLASS_CONSTANT_ACCESS && ! $location->getClassConstant() instanceof ClassConstantReflection
            ? sprintf('Reference to %s::class.', $class)
            : $location->createMessage($class);

        return InternalUse::restriction($use, $scope);
    }

    /**
     * True for a static call or a constant access that the member extensions report: the
     * member is marked, or its declaring class is. A member inherited from a class that is
     * not internal is reported here, as a use of the internal class name.
     */
    private function isReportedAsMember(ClassNameUsageLocation $location): bool
    {
        $member = match ($location->value) {
            ClassNameUsageLocation::STATIC_METHOD_CALL => $location->getMethod(),
            ClassNameUsageLocation::CLASS_CONSTANT_ACCESS => $location->getClassConstant(),
            default => null,
        };

        return $member !== null
            && (InternalUse::isMarked($member->getAttributes()) || InternalUse::isMarked($member->getDeclaringClass()->getAttributes()));
    }
}
