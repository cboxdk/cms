<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Content\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Domain\Queries\GetEntry;
use Cbox\Cms\Core\Entries\Domain\Queries\PreviewEntry;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\ListLimit;
use Cbox\Cms\Core\Schema\Domain\Queries\DescribeType;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Content\Domain\ContentEditor;
use Cbox\Cms\Panel\Content\Domain\Dto\EditorTarget;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Domain\Dto\ReadAnswer;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use LogicException;

/**
 * A request of the entry editor (PRD 5.4, 11.12, 13.4), read for its controller: what the address
 * asks for, the type its segment names, by the installation's editor descriptors (TypeEditors),
 * and the entry it names, or none for the page of a new entry; the view of the page, which renders
 * the shell's points; the address of the type's content list; and the page's reads as the person
 * who signed in, from the session credential (PanelReads): type.describe of the type, node.list of
 * the nodes a new entry's home and an entry's placements may be below, and for an entry entry.get of
 * the entry and entry.preview of the revision the address's query parameter `revision` names, or of
 * the draft, each answered as the page shows it, the result's document or the problem details of
 * a rejection. The controller holds no
 * logic, so what the page is made of is named here.
 */
#[Internal]
final readonly class ContentEditorRequest
{
    public function __construct(
        private ContributionProps $contributions,
        private PanelReads $reads,
        private TypeEditors $editors,
        private UrlGenerator $urls,
    ) {}

    /**
     * What the address asks for, or null when it names a type the installation does not have or
     * an entry that is no id.
     */
    public function target(Request $request): ?EditorTarget
    {
        $segment = $request->route('type');

        if (! is_string($segment)) {
            return null;
        }

        try {
            $type = $this->editors->named(new TypeName($segment));
        } catch (InvalidTypeDefinition) {
            return null;
        }

        if (! $type instanceof TypeEditor) {
            return null;
        }

        $entry = $request->route('entry');

        if (! is_string($entry)) {
            return new EditorTarget($type, null);
        }

        try {
            return new EditorTarget($type, EntryId::fromString(strtolower($entry)));
        } catch (InvalidUuid7) {
            return null;
        }
    }

    /**
     * The view of the page for the person: the shell's points.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function view(Request $request): PanelView
    {
        return $this->contributions->view($request, ContentEditor::PAGE);
    }

    /**
     * The address of the content list of the type, below which each entry's page is.
     */
    public function list(EditorTarget $target): string
    {
        return $this->urls->route(PanelRoute::ContentListOfType->value, ['type' => $target->type->name->value], false);
    }

    /**
     * The read of type.describe as the person.
     *
     * @param  Closure(QueryCall): QueryResult  $run
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function type(Request $request, EditorTarget $target, Closure $run): ReadAnswer
    {
        return $this->read($request, new DescribeType($target->type->name), ContentEditor::typeQuery(), $run);
    }

    /**
     * The read of entry.get as the person, or null for the page of a new entry.
     *
     * @param  Closure(QueryCall): QueryResult  $run
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function entry(Request $request, EditorTarget $target, Closure $run): ?ReadAnswer
    {
        return $target->entry instanceof EntryId
            ? $this->read($request, new GetEntry($target->entry), ContentEditor::entryQuery(), $run)
            : null;
    }

    /**
     * The read of node.list as the person: the nodes a new entry's home may be, and those an
     * entry's new placement may sit under.
     *
     * @param  Closure(QueryCall): QueryResult  $run
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function nodes(Request $request, Closure $run): ReadAnswer
    {
        return $this->read($request, new ListNodes(limit: ListLimit::MAX), ContentEditor::nodesQuery(), $run);
    }

    /**
     * The revision the address's query parameter `revision` names, or null for the draft: a
     * parameter that is missing, or that is not a whole number of 1 or more, previews the draft.
     */
    public function revision(Request $request): ?RevisionNumber
    {
        $value = $request->query(ContentEditor::REVISION_PARAMETER);

        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,8}\z/', $value) !== 1) {
            return null;
        }

        return new RevisionNumber((int) $value);
    }

    /**
     * The read of entry.preview as the person, the record of the entry's shared variant at the
     * revision, or its draft for null, as the delivery API would send it; null for the page of a
     * new entry.
     *
     * @param  Closure(QueryCall): QueryResult  $run
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function preview(Request $request, EditorTarget $target, ?RevisionNumber $revision, Closure $run): ?ReadAnswer
    {
        return $target->entry instanceof EntryId
            ? $this->read($request, new PreviewEntry($target->entry, revision: $revision), ContentEditor::previewQuery(), $run)
            : null;
    }

    /**
     * @param  Closure(QueryCall): QueryResult  $run
     */
    private function read(Request $request, Query $query, CommandRef $ref, Closure $run): ReadAnswer
    {
        return $this->reads->answer($run($this->reads->call($request, $query)), $this->reads->codec($ref));
    }
}
