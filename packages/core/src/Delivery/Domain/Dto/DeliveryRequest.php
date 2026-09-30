<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\TransportCredential;

/**
 * One request of the delivery API's resolve (PRD 8.9, 8.10), as the surface read it and before
 * anything checked it: the known parameters `site`, the host the page is requested at, `locale`
 * and `path`, each null when the request left it out, whether it asked for the explanation with
 * `debug=1`, and the credential it carried. Only the known parameters reach the kernel, so no other
 * parameter or header can change the answer (PRD 8.10 point 8), and the credential counts only for
 * a request that asks for the explanation.
 */
#[Internal]
final readonly class DeliveryRequest
{
    public function __construct(
        public ?string $site,
        public ?string $locale,
        public ?string $path,
        public ?string $debug = null,
        public ?TransportCredential $credential = null,
    ) {}
}
