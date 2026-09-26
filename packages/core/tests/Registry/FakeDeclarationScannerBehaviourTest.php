<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\Discovery;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeDeclarationScanner;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * DeclarationScannerBehaviour against the fake the registry's action tests use.
 */
final class FakeDeclarationScannerBehaviourTest extends TestCase
{
    use DeclarationScannerBehaviour;

    #[Override]
    protected function declarationScanner(): DeclarationScanner
    {
        return new FakeDeclarationScanner([
            $this->declaringDirectory() => RegistryFixtures::validDiscovery('acme/declared-for-the-fake'),
            $this->quietDirectory() => new Discovery([], [], [], []),
        ]);
    }

    #[Override]
    protected function declaringDirectory(): string
    {
        return '/srv/app/packages/notes/src';
    }

    #[Override]
    protected function quietDirectory(): string
    {
        return '/srv/app/packages/quiet/src';
    }
}
