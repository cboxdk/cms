<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Pipeline\Domain\RelationTargets;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRelationTargets;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * RelationTargetsBehaviour against the fake the pipeline's action tests use.
 */
final class FakeRelationTargetsBehaviourTest extends TestCase
{
    use RelationTargetsBehaviour;

    #[Override]
    protected function relationTargets(): RelationTargets
    {
        return new FakeRelationTargets()
            ->with(EntryId::fromString(self::ARTICLE), TypeId::fromString(self::ARTICLE_TYPE))
            ->with(EntryId::fromString(self::NOTE), TypeId::fromString(self::NOTE_TYPE));
    }
}
