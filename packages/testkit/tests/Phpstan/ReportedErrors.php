<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use PHPStan\Analyser\Error;

/**
 * Lists the errors of the testkit rules on a fixture as "line identifier", with
 * "(ignorable)" when a comment or ignoreErrors could hide the error. RuleTestCase::analyse()
 * compares messages only; this checks the identifiers. PHPStan's own errors about unmatched
 * ignore comments are left out.
 */
trait ReportedErrors
{
    public static function fixture(string $name): string
    {
        return __DIR__.'/Fixtures/'.$name.'.php.inc';
    }

    /**
     * @return list<string>
     */
    private function reported(string $fixture): array
    {
        $reported = [];

        foreach ($this->gatherAnalyserErrors([self::fixture($fixture)]) as $error) {
            $identifier = $error->getIdentifier() ?? '';

            if (str_starts_with($identifier, 'cboxCms.')) {
                $reported[] = sprintf('%d %s%s', $error->getLine() ?? 0, $identifier, $error->canBeIgnored() ? ' (ignorable)' : '');
            }
        }

        sort($reported, SORT_NATURAL);

        return $reported;
    }
}
