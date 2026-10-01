<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\AuthorizedOn;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What a command that moves a note's slug in one locale read: the note at its version, or null when
 * it was not found, the note's home and the node the note is placed under. The permission is checked
 * on the note's home, where its content lives, and on the placement's node in the locale, where its
 * slug lives; nothing is named when the note was not found, so the kernel refuses the command.
 */
final readonly class MoveNoteAggregates implements AuthorizedOn
{
    public function __construct(
        public EntryId $note,
        public ?AggregateVersion $version,
        public NodeId $home,
        public NodeId $placedUnder,
        public Locale $locale,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions($this->version instanceof AggregateVersion ? ReadVersion::at($this->note, $this->version) : ReadVersion::absent($this->note));
    }

    #[Override]
    public function authorizationTargets(): array
    {
        if (! $this->version instanceof AggregateVersion) {
            return [];
        }

        return [new AuthorizationTarget($this->home), new AuthorizationTarget($this->placedUnder, $this->locale)];
    }
}
