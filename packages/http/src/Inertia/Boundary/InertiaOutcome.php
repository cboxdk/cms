<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\DryRunSummary;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DryRunSummaryCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\MessageBag;
use Inertia\ResponseFactory;

/**
 * The Inertia profile's answer to a write (GUARDRAILS 2.1: redirects, and errors in page props).
 * Every answer is a 303 redirect back to the page the form was on, so the page is rendered again
 * with what the call left:
 *
 * - The receipt, in its JSON form (receipt.v1.json, written by the generated codec), is flashed
 *   under RECEIPT for every outcome: committed, committed_wait_timeout, dry_run and rejected. The
 *   page tells them apart by its outcome, so a committed_wait_timeout is never shown as a failure
 *   and a dry run never as a commit.
 * - A dry run also flashes its summary under DRY_RUN (dry-run-summary.v1.json, written by the
 *   generated codec): the blast radius, the version change of each aggregate and what becomes
 *   visible, which the page shows before the caller confirms the command for real.
 * - A rejected call also leaves its field errors in the page's `errors` prop, the prop Inertia's
 *   forms read, by the path of the value in the request body, such as command.fields.title, and
 *   the problem details document (problem.v1.json) with every catalog code and path, which the
 *   profile's middleware shares as the page prop PROBLEM_PROP. Its code, title and status are those
 *   of the first error, the one that decided the rejection, such as validation_failed or
 *   version_conflict; an error about the command as a whole, such as a version conflict, has no
 *   path and is only in the problem.
 *
 * The paths of a result's errors are relative to the command's document, so they are written below
 * `command`; a body the profile could not read has paths relative to the body already.
 */
#[Internal]
final readonly class InertiaOutcome
{
    /** The flash key of the receipt. */
    public const string RECEIPT = 'receipt';

    /** The flash key of a dry run's summary. */
    public const string DRY_RUN = 'dry_run';

    /** The session key the problem is flashed under for the next request. */
    public const string PROBLEM = 'cbox-cms.problem';

    /** The page prop the middleware shares the problem as. */
    public const string PROBLEM_PROP = 'problem';

    /** Inertia follows a 303 with a GET, whatever the method of the request was. */
    public const int STATUS = 303;

    public function __construct(
        private Redirector $redirector,
        private ResponseFactory $inertia,
        private ReceiptCodecV1 $receipts,
        private ProblemCodecV1 $problems,
        private DryRunSummaryCodecV1 $dryRuns,
    ) {}

    public function of(WriteResult $result): RedirectResponse
    {
        $this->inertia->flash(self::RECEIPT, InertiaProps::document($this->receipts->encode($result->receipt, ClassificationAccess::Public)));

        if ($result->dryRun instanceof DryRunReport) {
            $this->inertia->flash(self::DRY_RUN, InertiaProps::document($this->dryRuns->encode(DryRunSummary::of($result->dryRun), ClassificationAccess::Public)));
        }

        $errors = array_map(
            static fn (CatalogError $error): CatalogError => new CatalogError(
                $error->code,
                $error->path instanceof FieldPath ? new FieldPath(InertiaDocument::COMMAND, ...$error->path->segments) : null,
                $error->message,
            ),
            $result->errors,
        );

        return $errors === [] ? $this->back() : $this->rejected($errors[0], ...array_slice($errors, 1));
    }

    /**
     * The answer to a body the profile could not read: nothing ran, and the error's path is the
     * value's path in the body.
     */
    public function unreadable(DecodingFailed $failed): RedirectResponse
    {
        return $this->rejected(new CatalogError($failed->errorCode, $failed->path, $failed->reason));
    }

    private function rejected(CatalogError $first, CatalogError ...$more): RedirectResponse
    {
        $errors = [$first, ...array_values($more)];
        $fields = new MessageBag;

        foreach ($errors as $error) {
            if ($error->path instanceof FieldPath) {
                $fields->add($error->path->toString(), $error->message);
            }
        }

        $problem = Problem::of($first->code, $first->message, $errors);

        return $this->back()
            ->withErrors($fields)
            ->with(self::PROBLEM, InertiaProps::document($this->problems->encode($problem, ClassificationAccess::Public)));
    }

    private function back(): RedirectResponse
    {
        return $this->redirector->back(self::STATUS);
    }
}
