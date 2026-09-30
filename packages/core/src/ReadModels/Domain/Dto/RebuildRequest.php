<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Operations\Domain\InvalidOperation;
use Cbox\Cms\Core\Operations\Domain\OperationKey;

/**
 * A rebuild of one type's read model (PRD 4.1, invariant 22): the type and the name of the run. The
 * run names the operation with the type, `<type>@<run>`, so a second run with the same name resumes
 * the first where it stopped, and a run of another type with the same name is its own operation.
 */
#[Experimental]
final readonly class RebuildRequest
{
    public OperationKey $key;

    /**
     * @throws InvalidOperation when the run's name is not visible ASCII, or the key would be longer than OperationKey::MAX_LENGTH
     */
    public function __construct(
        public TypeName $type,
        public string $run,
    ) {
        $this->key = new OperationKey($type->value.'@'.$run);
    }
}
