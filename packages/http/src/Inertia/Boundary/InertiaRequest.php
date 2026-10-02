<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Http\Credentials\Boundary\RequestCredential;
use Cbox\Cms\Http\Inertia\Domain\Dto\InertiaInput;
use Illuminate\Http\Request;

/**
 * Reads an Inertia command request (GUARDRAILS 2.1, 2.2): its credential, the session the panel
 * authenticated or else the token of its Authorization header (RequestCredential), and its body, split by InertiaDocument, with the envelope fields read through the
 * envelope's generated codec. The envelope holds no classified field, so it is read with public
 * access. The command's document is left for the command's own codec, which reads it with the
 * caller's access once the credential is verified.
 */
#[Internal]
final readonly class InertiaRequest
{
    public function __construct(
        private EnvelopeCodecV1 $envelopes,
    ) {}

    /**
     * @throws DecodingFailed with the path of the value in the body, such as envelope.wait_level
     */
    public function read(Request $request): InertiaInput
    {
        $members = InertiaDocument::members($request->getContent());

        try {
            $envelope = $this->envelopes->decode($members->envelope, ClassificationAccess::Public);
        } catch (DecodingFailed $failed) {
            throw DecodingFailed::invalid(
                new FieldPath(InertiaDocument::ENVELOPE, ...($failed->path instanceof FieldPath ? $failed->path->segments : [])),
                $failed->reason,
                $failed,
            );
        }

        return new InertiaInput(RequestCredential::of($request), $envelope, $members->command);
    }
}
