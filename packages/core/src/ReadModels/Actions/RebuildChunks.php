<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Operations\Domain\ChunkedAction;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\ChunkPlan;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\ReadModels\Domain\RebuildTally;
use LogicException;
use Override;

/**
 * A rebuild of one type's read model as a chunked action (GUARDRAILS 4.2): one chunk per range of
 * the type's entries, each rebuilt by the ReadModelStore in a transaction of its own, and each
 * chunk's result added to the run's tally.
 *
 * The plan is made before the run by RebuildReadModels, which asks the store for it outside any
 * transaction; the runner asks for it only when it starts an operation. A run that resumes an
 * operation has no plan of its own, because the operation keeps the plan it started with, and asks
 * for none.
 */
#[Internal]
final readonly class RebuildChunks implements ChunkedAction
{
    public const string KIND = 'type_tables.rebuild';

    public function __construct(
        private TypeDefinition $type,
        private AccessContext $access,
        private ?ChunkPlan $plan,
        private ReadModelStore $store,
        private RebuildTally $tally,
    ) {}

    #[Override]
    public function kind(): OperationKind
    {
        return new OperationKind(self::KIND);
    }

    /**
     * @throws LogicException when the rebuild found a running operation to resume, which keeps its own plan
     */
    #[Override]
    public function chunks(): ChunkPlan
    {
        return $this->plan ?? throw new LogicException(sprintf('The rebuild of %s found an operation to resume and made no plan; the operation keeps the plan it started with.', $this->type->name->value));
    }

    #[Override]
    public function runChunk(ChunkName $chunk): void
    {
        $this->tally->add($this->store->rebuild($this->type, $this->access, EntryRange::fromChunk($chunk)));
    }
}
