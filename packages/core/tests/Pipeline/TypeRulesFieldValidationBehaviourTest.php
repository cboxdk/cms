<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Core\Pipeline\Boundary\TypeRulesFieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * FieldValidationBehaviour against the validation the application binds: the generated validator
 * through TypeValidators and the kernel's InputValidator.
 */
final class TypeRulesFieldValidationBehaviourTest extends TestCase
{
    use FieldValidationBehaviour;

    #[Override]
    protected function fieldValidation(TypeValidators $validators): FieldValidation
    {
        return new TypeRulesFieldValidation($validators, new InputValidator);
    }
}
