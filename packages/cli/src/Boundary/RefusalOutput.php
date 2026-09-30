<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;

/**
 * How the cms:* commands that only read (cms:actions, cms:hooks, cms:explain) print what stopped
 * them, with the exit code of the error catalog:
 *
 * - catalog errors, of a refusal with a code or of a rejected read: with --json the problem details
 *   (problem.v1.json, written by the generated codec) on standard output; without it each error as
 *   `<code> at <path>: <message>` and the error reference of the first on standard error. The exit
 *   code is the catalog's for the first error.
 * - a refusal without a code, such as wrong arguments: its message on standard error and nothing on
 *   standard output, with or without --json, and its exit code, such as 64.
 */
#[Internal]
final readonly class RefusalOutput
{
    public function __construct(private ProblemCodecV1 $problems) {}

    public function refused(CliCallRefused $refused, bool $json): CliAnswer
    {
        if ($refused->errorCode instanceof ErrorCode) {
            return $this->errors($json, new CatalogError($refused->errorCode, $refused->path, $refused->getMessage()));
        }

        return new CliAnswer($refused->exit, [], [$refused->getMessage()]);
    }

    public function errors(bool $json, CatalogError $first, CatalogError ...$more): CliAnswer
    {
        $errors = [$first, ...array_values($more)];
        $exit = $first->code->entry()->exit;

        if ($json) {
            return new CliAnswer($exit, [$this->problems->encode(Problem::of($first->code, $first->message, $errors), ClassificationAccess::Public)]);
        }

        $lines = array_map(
            static fn (CatalogError $error): string => $error->path instanceof FieldPath
                ? sprintf('%s at %s: %s', $error->code->value, $error->path->toString(), $error->message)
                : sprintf('%s: %s', $error->code->value, $error->message),
            $errors,
        );

        return new CliAnswer($exit, [], [...$lines, sprintf('See %s.', $first->code->docs())]);
    }
}
