<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Egress\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Egress\Domain\HostClass;
use InvalidArgumentException;

/**
 * An outbound GET: the host class it is counted under, the URL and the headers. The SSRF guard
 * checks the URL when the gateway sends it; the request itself only refuses an empty URL and a
 * header name given twice.
 */
#[Experimental]
final readonly class EgressRequest
{
    /**
     * @param  list<EgressHeader>  $headers
     *
     * @throws InvalidArgumentException when the URL is empty or a header name is given twice
     */
    public function __construct(
        public HostClass $hostClass,
        public string $url,
        public array $headers = [],
    ) {
        if ($url === '') {
            throw new InvalidArgumentException('An outbound request has a URL.');
        }

        $names = [];

        foreach ($headers as $header) {
            $name = strtolower($header->name);

            if (isset($names[$name])) {
                throw new InvalidArgumentException(sprintf('An outbound request has the header %s once.', $header->name));
            }

            $names[$name] = true;
        }
    }
}
