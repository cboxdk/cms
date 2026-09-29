<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Operations\Domain\OperationId;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Tests\Operations\OperationRunnerBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Cbox\Operations\Contracts\Operations;
use Cbox\Operations\DataObjects\FailOperation;
use Override;

/**
 * OperationRunnerBehaviour against PackageOperationRunner, the container's OperationRunner, on
 * laravel-operations and real Postgres, as the app role. A failed operation is failed through the
 * package's own contract, as its stall sweep does.
 */
final class PackageOperationRunnerBehaviourTest extends TestCase
{
    use OperationRunnerBehaviour;
    use RealPostgres;

    #[Override]
    protected function operationRunner(): OperationRunner
    {
        return app(OperationRunner::class);
    }

    #[Override]
    protected function failOperation(OperationId $id): void
    {
        app(Operations::class)->fail(new FailOperation($id->value, 'stalled: deadline exceeded'));
    }
}
