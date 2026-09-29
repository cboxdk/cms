<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres\Strictness;

use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * A model for the Eloquent strictness tests only: the children of a StrictnessParent.
 *
 * @property int $id
 * @property int $parent_id
 * @property string $label
 */
final class StrictnessChild extends Model
{
    #[Override]
    public $timestamps = false;

    #[Override]
    protected $table = StrictnessTables::CHILDREN;
}
