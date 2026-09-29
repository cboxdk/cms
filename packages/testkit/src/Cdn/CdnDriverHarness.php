<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cdn\CdnDriver;
use Cbox\Cms\Contracts\Cdn\CdnPurge;

/**
 * What the shared suite CdnDriverContract needs besides the driver: the edge's side of it.
 *
 * - driver() is the driver under test.
 * - requests() lists what the edge took, one CdnPurge per request, in order, with the keys and the
 *   mode the edge applied. A harness for a real driver records the requests at an endpoint that
 *   stands in for the CDN's API.
 * - interrupt() makes the edge refuse every request until restore().
 */
#[Experimental]
interface CdnDriverHarness
{
    public function driver(): CdnDriver;

    /**
     * @return list<CdnPurge>
     */
    public function requests(): array;

    public function interrupt(): void;

    public function restore(): void;
}
