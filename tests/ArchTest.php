<?php

declare(strict_types=1);

arch('every class in the packages and the workbench declares strict types')
    ->expect(['Cbox\Cms', 'Workbench\App'])
    ->toUseStrictTypes();

arch('no debug helpers are left in the code')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump'])
    ->not->toBeUsed();
