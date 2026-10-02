<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Invariants;

use Cbox\Cms\Contracts\Envelope\IssuingSurface;

/**
 * The way a covering test reaches an invariant (PRD 6.5: every invariant has tests that try to
 * break it through every command issuer).
 *
 * The first ten cases are the issuers of an envelope, IssuingSurface, by the same value: a test
 * names Rest, Inertia, Mcp or Cli when it goes through that surface's own transport, and an
 * internal issuer when it runs the kernel with that issuer's envelope. The other cases are where a
 * test holds the invariant below or beside the issuers: Delivery is GET /v1/resolve, Kernel the
 * command or query pipeline, an action or an operation called directly without a transport,
 * Database a statement as the app role that the schema's constraints, grants or policies refuse,
 * and StaticRule a rule that reads the code every issuer runs before it runs: PHPStan, the Arch
 * suite, cms:build or cms:generate.
 */
enum Issuer: string
{
    case Rest = 'rest';
    case Inertia = 'inertia';
    case Mcp = 'mcp';
    case Cli = 'cli';
    case Job = 'job';
    case Scheduler = 'scheduler';
    case Subscriber = 'subscriber';
    case Sidecar = 'sidecar';
    case Seed = 'seed';
    case Maintenance = 'maintenance';
    case Delivery = 'delivery';
    case Kernel = 'kernel';
    case Database = 'database';
    case StaticRule = 'static-rule';

    /**
     * The issuer of an envelope this case is, or null for a case that is none.
     */
    public function issuingSurface(): ?IssuingSurface
    {
        return IssuingSurface::tryFrom($this->value);
    }

    /**
     * The cases that are issuers of an envelope, in the order of IssuingSurface.
     *
     * @return list<self>
     */
    public static function envelopeIssuers(): array
    {
        return array_map(static fn (IssuingSurface $surface): self => self::from($surface->value), IssuingSurface::cases());
    }
}
