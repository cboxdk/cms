<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Access;

use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * AccessResolverBehaviour against the fake the query pipeline's action tests use.
 */
final class FakeAccessResolverBehaviourTest extends TestCase
{
    use AccessResolverBehaviour;

    private const string GRANTED = '01936f5e-8a2b-7c3d-9e4f-0000000000c1';

    private ?FakeAccessResolver $resolver = null;

    #[Override]
    protected function accessResolver(): AccessResolver
    {
        return $this->resolver();
    }

    #[Override]
    protected function grantedActor(): ActorId
    {
        return ActorId::fromString(self::GRANTED);
    }

    #[Override]
    protected function grantedRegions(): array
    {
        return [new AccessRegion(new NodePath('root.news.sport'))];
    }

    #[Override]
    protected function ungrantedActor(): ActorId
    {
        return ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000c2');
    }

    #[Override]
    protected function begin(): void
    {
        $this->resolver()->begin();
    }

    #[Override]
    protected function end(): void
    {
        $this->resolver()->rollBack();
    }

    private function resolver(): FakeAccessResolver
    {
        return $this->resolver ??= new FakeAccessResolver()->grant(ActorId::fromString(self::GRANTED), $this->grantedRegions(), ClassificationAccess::Internal);
    }
}
