<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Validators;

use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeValidators;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;

/**
 * The runtime validator of the comprehensive example type (PRD 11.12, MILESTONES M1 points 1 and
 * 2): what PhpTypeValidators writes for shop:product with the app's extension, and the committed
 * golden file, which Composer's autoload-dev loads as GOLDEN_CLASS and PHPStan analyses at level
 * 10 with the other tests.
 */
final class ComprehensiveValidator
{
    /** The PHP directory of the example, below ComprehensiveExample::DIRECTORY. */
    public const string PHP_DIRECTORY = 'Generated';

    /** The namespace of the example's generated PHP, which autoload-dev maps to PHP_DIRECTORY. */
    public const string PHP_NAMESPACE = 'Cbox\\Cms\\Generators\\Tests\\Descriptor\\Fixtures\\Comprehensive\\Generated';

    /** The golden file, below ComprehensiveExample::DIRECTORY. */
    public const string GOLDEN = self::PHP_DIRECTORY.'/'.PhpTypeValidators::DIRECTORY.'/ShopProductValidator.php';

    public const string GOLDEN_CLASS = self::PHP_NAMESPACE.'\\'.PhpTypeValidators::DIRECTORY.'\\ShopProductValidator';

    public static function target(): GenerationTarget
    {
        return new GenerationTarget(ComprehensiveExample::DIRECTORY, ComprehensiveExample::roots(), self::PHP_DIRECTORY, self::PHP_NAMESPACE, 'typescript/generated');
    }

    /**
     * What the generator writes for the example.
     *
     * @return list<GeneratedFile>
     */
    public static function generate(): array
    {
        return new PhpTypeValidators()->generate(ComprehensiveExample::compile(), self::target());
    }
}
