<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Testkit\Phpstan\Boundary\InternalUseRecords;
use PhpParser\Node;
use PHPStan\Analyser\CollectedDataEmitter;
use PHPStan\Analyser\Error;
use PHPStan\Analyser\IgnoreErrorExtension;
use PHPStan\Analyser\Scope;

/**
 * Takes every cboxCms.internalUse error out of the errors of the file PHPStan is analysing and
 * hands it to InternalUseRule as collected data, where it is reported again (GUARDRAILS 2.3).
 *
 * PHPStan's restricted usage rules build these errors, and their API cannot make an error
 * non-ignorable, so an ignore comment without an identifier or an ignoreErrors pattern could
 * hide one. PHPStan asks this extension about each ignorable error before any ignore comment
 * or ignoreErrors entry is applied; it answers that the error is ignored and emits it under
 * InternalUseCollector instead. InternalUseRule reports it after the analysis, non-ignorable
 * unless an ignore comment that names cboxCms.internalUse waives its line.
 *
 * InternalUseRule's own errors carry the metadata key InternalUseRule::REPORTED and are left
 * alone.
 */
#[Internal]
final class InternalUseIgnoreErrorExtension implements IgnoreErrorExtension
{
    public function shouldIgnore(Error $error, Node $node, Scope $scope): bool
    {
        if ($error->getIdentifier() !== InternalUse::IDENTIFIER || array_key_exists(InternalUseRule::REPORTED, $error->getMetadata()) || ! $scope instanceof CollectedDataEmitter) {
            return false;
        }

        $site = new InternalUseSite(
            file: $scope->getFile(),
            description: $error->getFile(),
            source: $error->getTraitFilePath() ?? $scope->getFile(),
            line: $error->getLine() ?? $node->getStartLine(),
            message: $error->getMessage(),
        );

        $scope->emitCollectedData(InternalUseCollector::class, [InternalUseRecords::site($site)]);

        return true;
    }
}
