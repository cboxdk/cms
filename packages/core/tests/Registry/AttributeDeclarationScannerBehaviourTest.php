<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * DeclarationScannerBehaviour against AttributeScanner, on the Valid fixture and an empty scratch
 * directory.
 */
final class AttributeDeclarationScannerBehaviourTest extends TestCase
{
    use DeclarationScannerBehaviour;

    private ?string $quiet = null;

    #[Override]
    protected function tearDown(): void
    {
        RegistryFixtures::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function declarationScanner(): DeclarationScanner
    {
        return new AttributeScanner;
    }

    #[Override]
    protected function declaringDirectory(): string
    {
        return RegistryFixtures::root('Valid')->directory;
    }

    #[Override]
    protected function quietDirectory(): string
    {
        if ($this->quiet === null) {
            $this->quiet = RegistryFixtures::scratch();
            mkdir($this->quiet);
        }

        return $this->quiet;
    }
}
