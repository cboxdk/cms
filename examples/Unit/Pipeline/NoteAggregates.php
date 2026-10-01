<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What SaveNoteAction::resolve() read: the note, or null when it does not exist yet, and the
 * placement a new note gets. versions() tells the kernel what to check at commit, and names every
 * aggregate the plan changes, as the kernel requires: the note's shared variant at the version it
 * was read at, or that the note, its shared variant and its placement are still absent.
 * authorizationScope() tells the kernel where to authorize the command: on the note's home node,
 * in every locale, because a note's title is shared by all of them.
 */
final readonly class NoteAggregates implements Aggregates
{
    public function __construct(
        public EntryId $note,
        public ?StoredNote $stored,
        public PlacementId $placement,
        public NodeId $home,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $shared = new VariantRef($this->note, VariantKey::shared());

        return $this->stored instanceof StoredNote
            ? new ReadVersions(ReadVersion::at($shared, $this->stored->version))
            : new ReadVersions(ReadVersion::absent($this->note), ReadVersion::absent($shared), ReadVersion::absent($this->placement));
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::on(new AuthorizationTarget($this->home));
    }
}
