<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * A checkout's test database could not be provisioned: the server does not answer, the owner
 * role lacks CREATEDB, or a set-up statement failed. The message names the database, the role and
 * the fix.
 */
#[Experimental]
final class TestDatabaseUnavailable extends RuntimeException {}
