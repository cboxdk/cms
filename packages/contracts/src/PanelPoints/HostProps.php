<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Attribute;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Names, on the props class of a panel point, the TypeScript type of what a component at the
 * point receives when the panel's host adds to the point's props in the browser what the server
 * never sends, such as a callback: the SDK exports that type beside the point's generated props,
 * from the subpath of the point's stability, and cms:panel:types types an addon's contribution to
 * the point on it instead of on the generated props. For example, a replacement at
 * `command.form.field@1` receives `FieldInputProps`, the point's `FieldInputPropsV1` and the
 * host's `onChange`, so the props class carries
 *
 *     #[HostProps('FieldInputProps')]
 *
 * A point without it hands a contribution exactly its generated props. It is the core's own
 * wiring between a point and the SDK, read by reflection when the types are written, so the
 * registry does not carry it; an addon declares no point and never uses it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Internal]
final readonly class HostProps
{
    /** A TypeScript type name the SDK exports: letters, digits and underscores, starting with a letter. */
    public const string NAME_PATTERN = '/\A[A-Za-z][A-Za-z0-9_]{0,99}\z/';

    /**
     * @param  string  $typeScript  the name of the exported TypeScript type
     *
     * @throws InvalidPanelPoint when the name is not a TypeScript type name
     */
    public function __construct(public string $typeScript)
    {
        if (preg_match(self::NAME_PATTERN, $typeScript) !== 1) {
            throw InvalidPanelPoint::because(sprintf('"%s" is not the name of a TypeScript type the SDK exports: letters, digits and underscores, starting with a letter, such as "FieldInputProps".', $typeScript));
        }
    }
}
