<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookOverrun;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookRun;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RefusedChange;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;

/**
 * Runs the hooks of a command for the command pipeline (GUARDRAILS 2.4, PRD 6.2, 6.3, 13.6).
 *
 * The hooks come from the compiled hooks registry through CommandHooks. Within a phase they run by
 * priority with the lowest first, then package name, then class. Each gets the pending plan as a
 * PlanView filtered to the call's classification access (HookPlans), never the plan itself; a hook
 * of an addon gets it filtered to the lower of that and what its manifest lets it read (PRD 13.1,
 * invariant 21), and cannot change a field above that. The view includes the revisions the plan's
 * releases make public, filtered in the same way (HookRun::$releases).
 *
 * - An authorize hook's denial stops the command as unauthorized with the hook's reason.
 * - A transform hook's changes enter the plan in order, and the next hook sees the changed plan; a
 *   change the kernel refuses stops the command as hook_change_refused.
 * - A validate hook's errors are added as validation_hook_failed, after the kernel's.
 *
 * Every hook is timed with the Stopwatch. A hook that took longer than its budget, or took the
 * command's hooks past the 100 ms they have together, stops the command as hook_budget_exceeded,
 * and the overrun is recorded through HookOverruns first.
 */
#[Internal]
final readonly class HookRunner
{
    public function __construct(
        private CommandHooks $hooks,
        private HookPlans $plans,
        private Stopwatch $stopwatch,
        private HookOverruns $overruns,
    ) {}

    /**
     * Every hook of the command, of every phase.
     *
     * @return list<BoundHook>
     */
    public function hooksOf(ActionBinding $binding): array
    {
        return $this->hooks->for($binding->command, $binding->version);
    }

    /**
     * Runs the hooks of one phase in order, and stops at the first that rejects the command.
     *
     * @param  list<BoundHook>  $hooks  the command's hooks, of every phase
     */
    public function run(Phase $phase, array $hooks, CommandCall $call, ActionBinding $binding, HookRun $run): HookRun
    {
        $ordered = array_values(array_filter($hooks, static fn (BoundHook $hook): bool => $hook->phase === $phase));
        usort($ordered, BoundHook::order(...));

        $view = null;
        $viewed = null;
        $viewedAs = null;

        foreach ($ordered as $hook) {
            $readable = $hook->access($call->access->classificationAccess);

            if (! $view instanceof PlanView || $viewed !== $run->plan || $viewedAs !== $readable) {
                $view = $this->plans->view($binding->command, $binding->version, $call->access, $run->plan, $readable, $run->releases, $call->issuedByAgent());
                $viewed = $run->plan;
                $viewedAs = $readable;
            }

            $started = $this->stopwatch->nanoseconds();
            $answer = match ($phase) {
                Phase::Authorize => $hook->authorize($view),
                Phase::Transform => $hook->transform($view),
                Phase::Validate => $hook->validate($view),
            };
            $charged = $run->budget->charge($hook, $this->stopwatch->nanoseconds() - $started, $binding->command, $binding->version);

            if ($charged instanceof HookOverrun) {
                $this->overruns->record($charged);

                return $run->rejectedWith(new CatalogError(ErrorCode::HookBudgetExceeded, null, $charged->describe().' The command was rejected, and nothing was committed.'));
            }

            $run = $this->answer($hook, $answer, $call, $run->charged($charged));

            if ($run->rejected()) {
                return $run;
            }
        }

        return $run;
    }

    private function answer(BoundHook $hook, HookDecision|FieldChanges|HookErrors $answer, CommandCall $call, HookRun $run): HookRun
    {
        if ($answer instanceof HookDecision) {
            return $answer->denies()
                ? $run->rejectedWith(new CatalogError(ErrorCode::Unauthorized, null, sprintf('The authorize hook %s of %s denied the command: %s', $hook->class, $hook->package, $answer->reason)))
                : $run;
        }

        if ($answer instanceof FieldChanges) {
            $changed = $this->plans->apply($run->plan, $answer, $call->access, $hook->access($call->access->classificationAccess), $call->issuedByAgent());

            return $changed instanceof Plan
                ? $run->withPlan($changed)
                : $run->rejectedWith($this->refused($hook, $changed));
        }

        return $run->withErrors(...array_map(
            fn (HookError $error): CatalogError => new CatalogError(ErrorCode::ValidationHookFailed, $this->path($error->namespace, $error->handle), $error->message),
            $answer->errors,
        ));
    }

    private function refused(BoundHook $hook, RefusedChange $refused): CatalogError
    {
        return new CatalogError(
            ErrorCode::HookChangeRefused,
            $this->path($refused->change->namespace, $refused->change->handle),
            sprintf('The transform hook %s of %s asked for a change a hook may not make: %s', $hook->class, $hook->package, $refused->reason),
        );
    }

    /**
     * The path of a field below the command's fields: the handle, or ext, the namespace and the
     * handle for an extension field, as the kernel's own field errors write it.
     */
    private function path(?FieldNamespace $namespace, ?FieldHandle $handle): ?FieldPath
    {
        if (! $handle instanceof FieldHandle) {
            return null;
        }

        return $namespace instanceof FieldNamespace
            ? new FieldPath(CommandPipeline::FIELDS, 'ext', $namespace->value, $handle->value)
            : new FieldPath(CommandPipeline::FIELDS, $handle->value);
    }
}
