<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Domain\CommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * CommandContentHasherBehaviour against the fake the pipeline's tests use.
 */
final class FakeCommandContentHasherBehaviourTest extends TestCase
{
    use CommandContentHasherBehaviour;

    #[Override]
    protected function hasher(): CommandContentHasher
    {
        return new FakeCommandContentHasher;
    }
}
