<?php

declare(strict_types=1);

use Cbox\Cms\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', '../packages/*/tests');
