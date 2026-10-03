<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why the panel withheld contributions from a page it rendered (PRD 13.4), as the telemetry of the
 * panel records it: the page renders, without the contributions, so one broken addon or an
 * unreadable registry never blanks it.
 *
 * - RegistryMissing and RegistryMalformed: the compiled registry could not be read, so the page
 *   has no contribution at all; cms:doctor's registry.cache says how to fix it.
 * - ActivationInvalid: cbox-cms.panel.disabled cannot be read, so the page shows no contribution
 *   rather than one the activation state may disable.
 * - PointWithoutCodec: no codec is registered for the point's props, so no contribution to it can
 *   be handed them.
 * - PropsUnencodable: the point's codec could not write the props for the contribution.
 */
#[Experimental]
enum Withheld: string
{
    case RegistryMissing = 'registry_missing';
    case RegistryMalformed = 'registry_malformed';
    case ActivationInvalid = 'activation_invalid';
    case PointWithoutCodec = 'point_without_codec';
    case PropsUnencodable = 'props_unencodable';
}
