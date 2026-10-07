<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * The props of the generic command form (PRD 6.1, 13.4), Command in js/panel: the address the
 * logout posts to, the command the form runs, by its name and contract version, and the JSON
 * Schema of the command's document as the command's codec carries it, from which the form is
 * rendered and the labels of its fields are read. The page validates the document it builds with
 * the rules of the schema before it submits, and submits through the Inertia profile at
 * `<commands>/<name>/v<version>`, the address the page is served at. Its JSON form is
 * command-form.v1.json in packages/panel/resources/schemas/pages, written only by the generated
 * CommandFormPageCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class CommandFormPage
{
    public function __construct(
        public string $logout,
        public CommandName $command,
        public int $version,
        public JsonDocument $schema,
    ) {}
}
