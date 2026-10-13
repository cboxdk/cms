<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Schema\Stages;

/**
 * The stage of a relation edge (PRD 5.11): the head's draft, or its released revision. A type with
 * stages none has only the released stage, which its saves write.
 */
#[Internal]
enum RelationStage: string
{
    case Draft = 'draft';
    case Released = 'released';

    /**
     * The stage a save of a draft writes for a type with the stages.
     */
    public static function ofSave(Stages $stages): self
    {
        return $stages === Stages::None ? self::Released : self::Draft;
    }
}
