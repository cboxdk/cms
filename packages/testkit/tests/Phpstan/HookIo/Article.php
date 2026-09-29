<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan\HookIo;

use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model the HookIo fixture queries from a hook. It lives outside the fixture, because
 * the rule asks PHPStan's reflection for its parents, which knows only autoloaded classes.
 */
final class Article extends Model {}
