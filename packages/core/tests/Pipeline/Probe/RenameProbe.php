<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;

/**
 * A test-only command for the command pipeline: writes a revision of an entry's shared variant
 * with the given fields, creating the entry below the home node when it does not exist. It is no
 * content type: the type is whatever TypeId the test gives it (GUARDRAILS 2.4). The caller may say
 * which versions it saw.
 */
#[Command('probe.rename', version: 1)]
final readonly class RenameProbe implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public TypeId $type,
        public NodeId $home,
        public FieldValues $fields,
        public ReadVersions $expected = new ReadVersions,
    ) {}

    public function expectedVersions(): ReadVersions
    {
        return $this->expected;
    }
}
