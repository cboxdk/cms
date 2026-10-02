<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

/**
 * How a part of the CI run ended, in GitHub Actions' words for `needs.<job>.result`: the gates
 * job, and the shard jobs together. bin/ci gives the same words for the parts of the
 * containerized run.
 */
enum JobResult: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
}
