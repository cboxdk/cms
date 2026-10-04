<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Cli\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;
use Cbox\Cms\Generators\Cli\Domain\MakePanelRefused;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ContributionRequest;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldKind;
use InvalidArgumentException;

/**
 * The arguments and options of cms:make:panel read into a ContributionRequest: the kind, the
 * addon, the id in the addon's namespace, and the point, command, query, severity, position and
 * patches, each refused with what was wrong.
 */
#[Internal]
final readonly class MakePanelInput
{
    private function __construct() {}

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     *
     * @throws MakePanelRefused
     */
    public static function read(array $arguments, array $options): ContributionRequest
    {
        $kind = ScaffoldKind::tryFrom(self::text($arguments, 'kind'));

        if (! $kind instanceof ScaffoldKind) {
            throw new MakePanelRefused(sprintf('The kind "%s" is not one cms:make:panel scaffolds: give fill, action, check or step.', self::text($arguments, 'kind')));
        }

        try {
            $namespace = new AddonNamespace(self::text($arguments, 'namespace'));
            $id = new ContributionId(self::text($arguments, 'id'));
        } catch (InvalidAddonManifest|ReservedAddonNamespace|InvalidPanelPoint $invalid) {
            throw new MakePanelRefused($invalid->getMessage(), 0, $invalid);
        }

        if ($id->namespace()->value !== $namespace->value) {
            throw new MakePanelRefused(sprintf('The contribution id %s is not in the namespace %s: an addon contributes only in its own namespace, such as %s.badge.', $id->value, $namespace->value, $namespace->value));
        }

        $command = self::ref($options, 'command');

        if ($kind !== ScaffoldKind::Fill && ! $command instanceof CommandRef && self::option($options, 'point') !== null) {
            throw new MakePanelRefused(sprintf('A %s is on a command: give --command=<name>@<version>, such as --command=entry.create@1.', $kind->value));
        }

        $severity = Severity::tryFrom(self::option($options, 'severity') ?? Severity::Warning->value);
        $position = StepPosition::tryFrom(self::option($options, 'position') ?? StepPosition::BeforeSubmit->value);

        if (! $severity instanceof Severity) {
            throw new MakePanelRefused('--severity takes info, warning, acknowledge or error.');
        }

        if (! $position instanceof StepPosition) {
            throw new MakePanelRefused('--position takes before_submit or after_receipt.');
        }

        return new ContributionRequest(
            $kind,
            $namespace,
            $id,
            self::point($options),
            $command,
            self::ref($options, 'query'),
            $severity,
            $position,
            self::patches($options),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function text(array $values, string $name): string
    {
        $value = $values[$name] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function option(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws MakePanelRefused
     */
    private static function point(array $options): ?PointId
    {
        $value = self::option($options, 'point');

        if ($value === null) {
            return null;
        }

        try {
            return PointId::fromString($value);
        } catch (InvalidPanelPoint|InvalidArgumentException $invalid) {
            throw new MakePanelRefused(sprintf('--point takes a point id, <name>@<version>, such as account.me.sections@1; "%s" is none: %s', $value, $invalid->getMessage()), 0, $invalid);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws MakePanelRefused
     */
    private static function ref(array $options, string $name): ?CommandRef
    {
        $value = self::option($options, $name);

        if ($value === null) {
            return null;
        }

        try {
            return CommandRef::fromString($value);
        } catch (InvalidPanelPoint|InvalidArgumentException $invalid) {
            throw new MakePanelRefused(sprintf('--%s takes a name and version, <name>@<version>, such as entry.create@1; "%s" is none: %s', $name, $value, $invalid->getMessage()), 0, $invalid);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     *
     * @throws MakePanelRefused
     */
    private static function patches(array $options): array
    {
        $patches = [];

        foreach (is_array($options['patch'] ?? null) ? $options['patch'] : [] as $patch) {
            if (! is_string($patch)) {
                continue;
            }

            try {
                $patches[] = FieldPath::fromString($patch)->toString();
            } catch (InvalidWriteResult $invalid) {
                throw new MakePanelRefused(sprintf('--patch takes a path of a command document, such as fields.ext.approvals.reason; "%s" is none: %s', $patch, $invalid->getMessage()), 0, $invalid);
            }
        }

        return $patches;
    }
}
