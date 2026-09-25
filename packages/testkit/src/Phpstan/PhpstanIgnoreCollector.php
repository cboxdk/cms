<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;
use RuntimeException;
use SplFileObject;

/**
 * Collects the lines of the phpstan-ignore annotations outside Boundary and Adapter, once per
 * analysed file, from the tokens of the file (GUARDRAILS 2.2, gate 3).
 *
 * It reads tokens and not the AST, because PHPStan attaches some comments to no node. The
 * annotations are reported by PhpstanIgnoreRule after the analysis.
 *
 * @implements Collector<FileNode, list<int>>
 */
#[Internal]
final class PhpstanIgnoreCollector implements Collector
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @return list<int>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $lines = [];

        foreach (IgnoreCommentScanner::scan($this->read($scope->getFile())) as $comment) {
            if (! LayerScope::allowsLooseTypes($comment->namespace)) {
                $lines[] = $comment->line;
            }
        }

        return $lines === [] ? null : $lines;
    }

    /**
     * Reads the analysed file from the local disk. PHPStan's own file reader is not covered
     * by its backward compatibility promise, so the file is read here.
     */
    private function read(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("PHPStan analyses {$path}, which is not a local file.");
        }

        $file = new SplFileObject($path, 'r');
        $code = '';

        while (! $file->eof()) {
            $code .= $file->fgets();
        }

        return $code;
    }
}
