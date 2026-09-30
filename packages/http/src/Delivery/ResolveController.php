<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Delivery;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Delivery\Actions\DeliverPath;
use Cbox\Cms\Http\Delivery\Boundary\DeliveryInput;
use Cbox\Cms\Http\Delivery\Boundary\DeliveryOutput;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /v1/resolve (PRD 8.9, 8.10): DeliveryInput reads the known parameters and the credential,
 * DeliverPath answers from a fragment or through the query pipeline, and DeliveryOutput writes the
 * answer with its cache headers. The controller holds no logic of its own.
 */
#[Internal]
final readonly class ResolveController
{
    public function __construct(private DeliverPath $deliver) {}

    public function __invoke(Request $request): Response
    {
        return DeliveryOutput::response($this->deliver->deliver(DeliveryInput::request($request)));
    }
}
