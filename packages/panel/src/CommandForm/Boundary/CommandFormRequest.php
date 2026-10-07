<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\ContractSummaries;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;
use Cbox\Cms\Panel\CommandForm\Domain\Dto\CommandFormChecksV1;
use Cbox\Cms\Panel\CommandForm\Domain\Dto\CommandFormContextV1;
use Cbox\Cms\Panel\CommandForm\Domain\Dto\CommandFormStepsV1;
use Cbox\Cms\Panel\CommandForm\Domain\Dto\CommandFormSubmitV1;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ViewSubject;
use Cbox\Cms\Panel\Domain\Dto\CommandFormPage;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use LogicException;

/**
 * A request of the generic command form (PRD 6.1, 13.4), read for its controller: the command the
 * address names, which must be a write action the Inertia profile exposes and whose document a
 * codec reads, or there is no form, as there is no route for the command's post; the page's
 * props, with the command's JSON Schema as the codec carries it and the value classes it binds its
 * members to (CommandBindings); and the view of the page for the person, which renders the form's
 * points, the aside and the submit decorator with the command, its version and the schema's title
 * (the ContractSummaries of the registry, the title action.list lists the command by) as their
 * props, and names the command as the view's subject, so a contribution whose scope names
 * commands is active on this command's form alone. The controller holds no logic, so what the form
 * is made of is named here.
 */
#[Internal]
final readonly class CommandFormRequest
{
    public function __construct(
        private InertiaActions $actions,
        private CommandCodecs $codecs,
        private ContractSummaries $summaries,
        private ContributionProps $contributions,
        private UrlGenerator $urls,
    ) {}

    /**
     * The page's props for the command the address names, or null when the Inertia profile exposes
     * no such version of the command or no codec reads its document.
     */
    public function form(string $command, string $version): ?CommandFormPage
    {
        $entry = $this->actions->find(new CommandName($command), (int) $version);
        $codec = $entry instanceof ActionEntry ? $this->codecs->find($entry->command, $entry->commandVersion) : null;

        if (! $entry instanceof ActionEntry || ! $codec instanceof CommandCodec) {
            return null;
        }

        return new CommandFormPage(
            $this->urls->route(PanelRoute::Logout->value, [], false),
            $entry->command,
            $entry->commandVersion,
            new JsonDocument($codec->schema->json),
            CommandBindings::of($entry->commandClass, SchemaProperties::of($codec->schema)),
        );
    }

    /**
     * The view of the page for the person: the form's points, and the command as its subject. The
     * aside and the submit decorator get what the form is about; the checks and the steps have no
     * props; the receipt, the field inputs and the dry run exist only in the browser, so the page
     * holds their props.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function view(Request $request, CommandFormPage $form): PanelView
    {
        $title = $this->title($form);

        return $this->contributions->view(
            $request,
            CommandForm::PAGE,
            [
                new RenderedPoint(CommandForm::aside(), new CommandFormContextV1($form->command, $form->version, $title)),
                new RenderedPoint(CommandForm::checks(), new CommandFormChecksV1),
                new RenderedPoint(CommandForm::steps(), new CommandFormStepsV1),
                new RenderedPoint(CommandForm::submit(), new CommandFormSubmitV1($form->command, $form->version, $title)),
                RenderedPoint::heldByPage(CommandForm::receipt()),
                RenderedPoint::heldByPage(CommandForm::field()),
                RenderedPoint::heldByPage(CommandForm::dryRun()),
            ],
            new ViewSubject(new CommandRef($form->command, $form->version)),
        );
    }

    /**
     * The title of the command's JSON Schema, or `<name>, contract version <version>` when the
     * schema has none.
     */
    private function title(CommandFormPage $form): string
    {
        return $this->summaries->of(ActionKind::Write, $form->command, $form->version)->title
            ?? sprintf('%s, contract version %d', $form->command->value, $form->version);
    }
}
