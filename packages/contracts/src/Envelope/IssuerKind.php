<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What kind of issuer runs a command, as the changeset records it (PRD 5.5, aktørtype).
 */
#[Experimental]
enum IssuerKind: string
{
    case Human = 'human';
    case Agent = 'agent';
    case Seed = 'seed';
    case Migration = 'migration';
    case Scheduler = 'scheduler';
    case Sync = 'sync';
    case System = 'system';
}
