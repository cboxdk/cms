<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Boundary\InertiaRequest;
use Cbox\Cms\Http\Inertia\Domain\Dto\InertiaInput;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A write through the Inertia profile (GUARDRAILS 2.1): the route names the command and its
 * version, which must be a write action the registry exposes on Inertia, or the answer is 404. The
 * request is read by InertiaRequest, the call runs through RunExposedCommand with the command's
 * codec, and InertiaOutcome translates the typed result into a redirect. The controller holds no
 * logic of its own.
 */
#[Internal]
final readonly class InertiaCommandController
{
    public function __construct(
        private InertiaActions $actions,
        private CommandCodecs $codecs,
        private InertiaRequest $requests,
        private RunExposedCommand $run,
        private InertiaOutcome $outcomes,
    ) {}

    public function __invoke(Request $request, string $command, string $version): RedirectResponse
    {
        $entry = $this->actions->find(new CommandName($command), (int) $version)
            ?? throw new NotFoundHttpException(sprintf('The Inertia profile exposes no version %s of the command %s.', $version, $command));

        try {
            $input = $this->requests->read($request);
        } catch (DecodingFailed $failed) {
            return $this->outcomes->unreadable($failed);
        }

        return $this->outcomes->of($this->run->run($this->call($input, $entry->command, $entry->commandVersion)));
    }

    private function call(InertiaInput $input, CommandName $command, int $version): ExposedCall
    {
        return new ExposedCall(Surface::Inertia, $input->credential, $input->envelope, $this->codecs->for($command, $version), $input->command);
    }
}
