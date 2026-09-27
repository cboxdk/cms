<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Testkit\Phpstan\Boundary\InternalUseRecords;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;

/**
 * The data InternalUseRule reports cboxCms.internalUse from, as InternalUseRecords strings.
 *
 * Once per analysed file it collects the waivers: the lines PHPStan's own ignore comments
 * mark with the identifier cboxCms.internalUse, and how often. It reads them from the
 * attribute PHPStan's parser puts on the first node of the file, the lines PHPStan itself
 * matches ignore comments against, so a waiver sits on exactly the line PHPStan applies the
 * comment to. The ignore-line and ignore-next-line forms mark a line with no identifier and are
 * no waiver. Should PHPStan stop setting the attribute, no line is waived and
 * every use is reported, and PHPStan reports each comment as matching no error.
 *
 * InternalUseIgnoreErrorExtension adds a site under this collector for each use.
 *
 * @implements Collector<FileNode, list<string>>
 */
#[Internal]
final class InternalUseCollector implements Collector
{
    /** The attribute PHPStan's parser sets to the lines its ignore comments apply to. */
    public const string LINES_TO_IGNORE = 'linesToIgnore';

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<string>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $lines = ($node->getNodes()[0] ?? null)?->getAttribute(self::LINES_TO_IGNORE);
        $records = [];

        foreach (is_array($lines) ? $lines : [] as $line => $identifiers) {
            $count = 0;

            foreach (is_array($identifiers) ? $identifiers : [] as $identifier) {
                if (is_array($identifier) && ($identifier['name'] ?? null) === InternalUse::IDENTIFIER) {
                    $count++;
                }
            }

            if (is_int($line) && $count > 0) {
                $records[] = InternalUseRecords::waiver(new InternalUseWaiver($scope->getFile(), $line, $count));
            }
        }

        return $records === [] ? null : $records;
    }
}
