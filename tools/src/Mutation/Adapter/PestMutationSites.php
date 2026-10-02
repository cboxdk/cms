<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use InvalidArgumentException;
use Pest\Mutate\Contracts\Mutator;
use Pest\Mutate\Factories\NodeTraverserFactory;
use Pest\Mutate\Support\PhpParserFactory;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Where a mutator of pest-plugin-mutate can mutate a PHP source: the line each mutation it would
 * make starts on, read from the source's syntax tree as Pest reads it, without making the
 * mutations or running a test. EquivalentMutations::unsited() holds the list of equivalent
 * mutations to the sources as they are with it.
 */
final readonly class PestMutationSites
{
    /**
     * The lines, sorted and each once, where $mutator can mutate $source.
     *
     * @return list<int>
     */
    public static function lines(string $source, string $mutator): array
    {
        if (! is_a($mutator, Mutator::class, true)) {
            throw new InvalidArgumentException("[{$mutator}] is not a mutator of pest-plugin-mutate.");
        }

        $statements = PhpParserFactory::make()->parse($source);

        if ($statements === null) {
            throw new InvalidArgumentException('The source cannot be parsed.');
        }

        $collector = new class($mutator) extends NodeVisitorAbstract
        {
            /** @var array<int, int> */
            public array $lines = [];

            /**
             * @param  class-string<Mutator>  $mutator
             */
            public function __construct(private readonly string $mutator) {}

            public function leaveNode(Node $node): null
            {
                if ($this->mutator::can($node)) {
                    $this->lines[$node->getStartLine()] = $node->getStartLine();
                }

                return null;
            }
        };

        $traverser = NodeTraverserFactory::create();
        $traverser->addVisitor($collector);
        $traverser->traverse($statements);

        $lines = array_values($collector->lines);
        sort($lines);

        return $lines;
    }
}
