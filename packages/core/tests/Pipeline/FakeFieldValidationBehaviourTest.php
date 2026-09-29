<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * FieldValidationBehaviour against the fake the pipeline's action tests use.
 */
final class FakeFieldValidationBehaviourTest extends TestCase
{
    use FieldValidationBehaviour;

    #[Override]
    protected function fieldValidation(TypeValidators $validators): FieldValidation
    {
        return new FakeFieldValidation($validators);
    }
}
