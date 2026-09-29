<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Operations;

use Cbox\Cms\Core\Operations\Domain\OperationId;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Tests\Operations\Fakes\FakeOperationRunner;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * OperationRunnerBehaviour against the fake the tests of what runs operations use. The runner on
 * laravel-operations runs the same cases in PackageOperationRunnerBehaviourTest.
 */
final class FakeOperationRunnerBehaviourTest extends TestCase
{
    use OperationRunnerBehaviour;

    private ?FakeOperationRunner $runner = null;

    #[Override]
    protected function operationRunner(): OperationRunner
    {
        return $this->runner ??= new FakeOperationRunner;
    }

    #[Override]
    protected function failOperation(OperationId $id): void
    {
        ($this->runner ??= new FakeOperationRunner)->fail($id);
    }
}
