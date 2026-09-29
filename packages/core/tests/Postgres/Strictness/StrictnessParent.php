<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres\Strictness;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * A model for the Eloquent strictness tests only, on the scratch table StrictnessTables makes.
 * `name` is fillable and `note` is not, so filling `note` is a mass assignment of an unfillable
 * attribute.
 *
 * @property int $id
 * @property string $name
 * @property string $note
 */
#[Fillable(['name'])]
final class StrictnessParent extends Model
{
    #[Override]
    public $timestamps = false;

    #[Override]
    protected $table = StrictnessTables::PARENTS;

    /**
     * @return HasMany<StrictnessChild, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(StrictnessChild::class, 'parent_id');
    }
}
