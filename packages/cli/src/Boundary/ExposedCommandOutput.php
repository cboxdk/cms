<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1;

/**
 * The CLI profile's answer to a write (GUARDRAILS 2.1: exit codes from the error catalog):
 *
 * - The exit code: 0 for committed and committed_wait_timeout, the exit code of dry_run in the
 *   catalog (0) for a dry run, and for a rejected call the catalog's exit code of its first error,
 *   the one that decided the rejection, such as 65 for validation_failed and version_conflict and
 *   77 for unauthorized. A committed_wait_timeout committed the change, so it is no failure.
 * - With --json, standard output is one line: the receipt (receipt.v1.json) of a call that was not
 *   rejected, and the problem details (problem.v1.json) with every catalog code and path of a
 *   rejected call or of a refusal that has a catalog code. Both are written by the generated codecs.
 * - Without it, a sentence on standard output for a call that was not rejected, and each error as
 *   `<code> at <path>: <message>` and the error reference of the first on standard error.
 *
 * A refusal without a catalog code, a usage error, an invalid setting or a command without a
 * codec, prints its message on standard error and nothing on standard output, with or without
 * --json.
 */
#[Internal]
final readonly class ExposedCommandOutput
{
    public function __construct(
        private ReceiptCodecV1 $receipts,
        private ProblemCodecV1 $problems,
    ) {}

    public function of(WriteResult $result, bool $json): CliAnswer
    {
        $receipt = $result->receipt;

        if ($receipt->outcome === Outcome::Rejected && $result->errors !== []) {
            return $this->rejected($json, $result->errors[0], ...array_slice($result->errors, 1));
        }

        $exit = $receipt->outcome === Outcome::DryRun ? ErrorCode::DryRun->entry()->exit : ExitCode::Ok;

        if ($json) {
            return new CliAnswer($exit, [$this->receipts->encode($receipt, ClassificationAccess::Public)]);
        }

        $changeset = $receipt->changesetId instanceof ChangesetId ? $receipt->changesetId->toString() : '';

        return new CliAnswer($exit, [match ($receipt->outcome) {
            Outcome::DryRun => 'Dry run: the command would commit. Nothing was committed; run it again without --dry-run to commit it.',
            Outcome::CommittedWaitTimeout => sprintf('Committed changeset %s, but wait level %s was not reached in time. The change is committed; do not run it again.', $changeset, $receipt->waitLevel->value),
            default => sprintf('Committed changeset %s, wait level %s reached.', $changeset, $receipt->waitLevel->value),
        }]);
    }

    public function refused(CliCallRefused $refused, bool $json): CliAnswer
    {
        if ($refused->errorCode instanceof ErrorCode) {
            return $this->rejected($json, new CatalogError($refused->errorCode, $refused->path, $refused->getMessage()));
        }

        return new CliAnswer($refused->exit, [], [$refused->getMessage()]);
    }

    private function rejected(bool $json, CatalogError $first, CatalogError ...$more): CliAnswer
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

        return new CliAnswer($exit, [], [...$lines, sprintf('Rejected; nothing was committed. See %s.', $first->code->docs())]);
    }
}
