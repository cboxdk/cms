<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Subscribers\Lane;

/**
 * An addon's manifest (PRD 13.1): everything the addon does through the kernel, declared once.
 * An addon's service provider returns it from DeclaresAddon::addonManifest(), and cms:build
 * compiles it with the addon's scan roots.
 *
 * - package: the addon's Composer package name. Its scan roots carry the same name, and every
 *   hook and subscriber they hold is held to this manifest.
 * - namespace: the addon's name and the namespace of its fields and field types, unique in the
 *   installation.
 * - coreApi: the version of the kernel's API it needs, read as ^major.minor.
 * - docs: the absolute directory of the addon's documentation, which it must have.
 * - capabilities: what the kernel hands it (AddonCapabilities).
 * - hooks: the command and phase of each hook it may register. The hook classes declare
 *   themselves with #[Hook]; the manifest allows them.
 * - subscriptions: each event class its subscribers may receive, with the lane.
 * - schema: its field types, own types and blueprint extensions (SchemaContributions).
 *
 * The constructor holds the manifest to its namespace: the field types and own types are in it,
 * and it extends no type of its own. It refuses a hook or a subscription listed twice.
 */
#[Experimental]
final readonly class AddonManifest
{
    public string $docs;

    /** @var list<AllowedHook> */
    public array $hooks;

    /** @var list<AllowedSubscription> */
    public array $subscriptions;

    /**
     * @param  list<AllowedHook>  $hooks
     * @param  list<AllowedSubscription>  $subscriptions
     *
     * @throws InvalidAddonManifest when the manifest breaks a rule above
     */
    public function __construct(
        public string $package,
        public AddonNamespace $namespace,
        public CoreApiVersion $coreApi,
        string $docs,
        public AddonCapabilities $capabilities = new AddonCapabilities,
        array $hooks = [],
        array $subscriptions = [],
        public SchemaContributions $schema = new SchemaContributions,
    ) {
        if (preg_match(ScanRoot::PACKAGE_PATTERN, $package) !== 1) {
            throw InvalidAddonManifest::because(sprintf('The addon package "%s" is not a Composer package name such as "acme/cms-reviews".', $package));
        }

        $this->docs = ClassNames::absoluteDirectory('documentation', $docs);
        $this->hooks = $this->distinct('hook', $hooks, static fn (AllowedHook $hook): string => strtolower($hook->command).' '.$hook->phase->value, static fn (AllowedHook $hook): string => sprintf('%s in the %s phase', $hook->command, $hook->phase->value));
        $this->subscriptions = $this->distinct('subscription', $subscriptions, static fn (AllowedSubscription $allowed): string => strtolower($allowed->event).' '.$allowed->lane->value, static fn (AllowedSubscription $allowed): string => sprintf('%s on the %s lane', $allowed->event, $allowed->lane->value));

        foreach ($schema->fieldTypes as $fieldType) {
            if ($fieldType->namespace !== $namespace->value) {
                throw InvalidAddonManifest::because(sprintf(
                    'The addon "%s" contributes the field type "%s", which is outside its namespace. Name its field types "%s:<handle>" (PRD 13.1).',
                    $namespace->value,
                    $fieldType->value,
                    $namespace->value,
                ));
            }
        }

        foreach ($schema->types as $type) {
            if ($type->owner !== $namespace->value) {
                throw InvalidAddonManifest::because(sprintf(
                    'The addon "%s" owns the type "%s", which is outside its namespace. Name its own types "%s:<handle>" (PRD 11.12).',
                    $namespace->value,
                    $type->value,
                    $namespace->value,
                ));
            }
        }

        foreach ($schema->extends as $type) {
            if ($type->owner === $namespace->value) {
                throw InvalidAddonManifest::because(sprintf(
                    'The addon "%s" extends its own type "%s". An owner adds fields to its own type in the type\'s blueprint, not in an extension (PRD 11.12).',
                    $namespace->value,
                    $type->value,
                ));
            }
        }
    }

    /**
     * Whether the manifest allows a hook of the phase on the command class.
     */
    public function allowsHook(string $command, Phase $phase): bool
    {
        return array_any($this->hooks, static fn (AllowedHook $hook): bool => $hook->allows($command, $phase));
    }

    /**
     * Whether the manifest allows a subscriber to receive the event class on the lane.
     */
    public function allowsSubscription(string $event, Lane $lane): bool
    {
        return array_any($this->subscriptions, static fn (AllowedSubscription $allowed): bool => $allowed->allows($event, $lane));
    }

    /**
     * @template T of object
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $key
     * @param  callable(T): string  $describe
     * @return list<T>
     *
     * @throws InvalidAddonManifest when two items have one key
     */
    private function distinct(string $what, array $items, callable $key, callable $describe): array
    {
        $seen = [];

        foreach ($items as $item) {
            $itemKey = $key($item);

            if (array_key_exists($itemKey, $seen)) {
                throw InvalidAddonManifest::because(sprintf('The %s %s is listed twice.', $what, $describe($item)));
            }

            $seen[$itemKey] = true;
        }

        return $items;
    }
}
