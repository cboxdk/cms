<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;

/**
 * The prop `types` every page behind the login shares (PRD 11.12, 13.4): the address of the content
 * list, below which the list of a type is `<content>/<owner>:<handle>`, and the read of type.list
 * as the person, its result, the document of type.list.result.v1.json the query's result codec wrote
 * at the person's classification access, or the rejection, the problem details (problem.v1.json)
 * of a rejected read, never both, and neither for a read the pipeline could not make. Its JSON form
 * is types.v1.json in packages/panel/resources/schemas/pages, written only by the generated
 * TypesPropCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class TypesProp
{
    public function __construct(
        public string $content,
        public ?JsonDocument $result,
        public ?JsonDocument $rejection,
    ) {}
}
