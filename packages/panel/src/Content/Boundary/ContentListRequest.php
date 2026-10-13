<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Content\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Editors\TypeEditor;
use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Panel\Access\Boundary\ListCursor;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Content\Domain\ContentList;
use Cbox\Cms\Panel\Content\Domain\Dto\ContentTarget;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Domain\Dto\ReadAnswer;
use Illuminate\Http\Request;
use LogicException;

/**
 * A request of the content list (PRD 5.4, 13.4), read for its controller: what the address asks
 * for, the type its segment names, by the installation's editor descriptors (TypeEditors), the
 * release state `?state=<state>` names and the entry `?after=<id>` names (ListCursor), a value
 * that is no state or no id being left out as a missing one is; the view of the page, which
 * renders the shell's points; the call of entry.list as the person who signed in, from the session
 * credential (PanelReads), for a page of ContentList::LIMIT entries; and the read's answer as the
 * page shows it, the result's document or the problem details of a rejection. The controller holds
 * no logic, so what the page is made of is named here.
 */
#[Internal]
final readonly class ContentListRequest
{
    public function __construct(
        private ContributionProps $contributions,
        private PanelReads $reads,
        private TypeEditors $editors,
    ) {}

    /**
     * What the address asks for, or null when it names a type the installation does not have.
     */
    public function target(Request $request): ?ContentTarget
    {
        $segment = $request->route('type');
        $type = null;

        if (is_string($segment)) {
            try {
                $type = $this->editors->named(new TypeName($segment));
            } catch (InvalidTypeDefinition) {
                return null;
            }

            if (! $type instanceof TypeEditor) {
                return null;
            }
        }

        $state = $request->query(ContentList::STATE);

        return new ContentTarget(
            $type,
            is_string($state) ? ReleaseState::tryFrom($state) : null,
            ListCursor::of($request, EntryId::fromString(...)),
        );
    }

    /**
     * The view of the page for the person: the shell's points.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function view(Request $request): PanelView
    {
        return $this->contributions->view($request, ContentList::PAGE);
    }

    /**
     * The call of entry.list as the person who signed in, for the page the target asks for.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function call(Request $request, ContentTarget $target): QueryCall
    {
        return $this->reads->call($request, new ListEntries(
            type: $target->type?->id,
            state: $target->state,
            after: $target->after,
            limit: ContentList::LIMIT,
        ));
    }

    /**
     * The answer of the read as the page shows it.
     */
    public function answer(QueryResult $read): ReadAnswer
    {
        return $this->reads->answer($read, $this->reads->codec(ContentList::query()));
    }
}
