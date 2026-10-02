<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Staff;

use Cbox\Cms\Tests\TestCase;
use Illuminate\Testing\PendingCommand;
use Pest\TestSuite;
use PHPUnit\Framework\AssertionFailedError;

/**
 * cms:staff:create as the running test calls it, through Laravel's PendingCommand, which answers
 * the hidden password questions the way a person types them in a terminal.
 */
final readonly class StaffCommand
{
    public const string NAME = 'cms:staff:create';

    /**
     * @param  array<array-key, mixed>  $options
     */
    public static function pending(array $options): PendingCommand
    {
        $test = TestSuite::getInstance()->test;

        if (! $test instanceof TestCase) {
            throw new AssertionFailedError('cms:staff:create runs in the workbench\'s test case.');
        }

        $pending = $test->artisan(self::NAME, $options);

        return $pending instanceof PendingCommand ? $pending : throw new AssertionFailedError('Laravel ran cms:staff:create without a PendingCommand; the test mocks the console.');
    }
}
