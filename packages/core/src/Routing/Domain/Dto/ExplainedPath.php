<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Consistency\CommitPosition;

/**
 * What cms:explain --json prints for an answered read (GUARDRAILS 5 and 7.1),
 * explained-path.v1.json: the explanation of the resolution, a document of
 * path-explanation.v1.json, the content keys the answer is tagged with (PRD 9.4), each once and
 * sorted, and the position of the read. The generated codec ExplainedPathCodecV1 writes it.
 */
#[Experimental]
final readonly class ExplainedPath
{
    /** @var list<DependencyKey> each once, sorted */
    public array $contentKeys;

    /**
     * @param  list<DependencyKey>  $contentKeys
     */
    public function __construct(
        array $contentKeys,
        public JsonDocument $explanation,
        public CommitPosition $readPosition,
    ) {
        $byKey = [];

        foreach ($contentKeys as $key) {
            $byKey[$key->toString()] = $key;
        }

        ksort($byKey, SORT_STRING);
        $this->contentKeys = array_values($byKey);
    }
}
