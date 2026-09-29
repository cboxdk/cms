<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Domain\Dto\SubscribedEvent;
use InvalidArgumentException;

/**
 * A registry entry was given a value it cannot hold. The registry DTOs check their values, so an
 * entry read from a damaged cache file fails the same way as one built wrongly in code.
 */
#[Experimental]
final class InvalidRegistryEntry extends InvalidArgumentException
{
    /** A fully qualified PHP class name without a leading backslash. */
    private const string CLASS_PATTERN = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    public static function checkClass(string $field, string $class): string
    {
        if (preg_match(self::CLASS_PATTERN, $class) !== 1) {
            throw new self(sprintf('The %s "%s" is not a fully qualified class name.', $field, $class));
        }

        return $class;
    }

    public static function checkPackage(string $package): string
    {
        if (preg_match(ScanRoot::PACKAGE_PATTERN, $package) !== 1) {
            throw new self(sprintf('The package "%s" is not a Composer package name.', $package));
        }

        return $package;
    }

    /**
     * The surfaces of an action, each once and in the order of Surface's cases, as #[Action] gives
     * them, so an entry has one form and the same entries give the same bytes.
     *
     * @param  list<Surface>  $surfaces
     * @return list<Surface>
     */
    public static function checkSurfaces(string $action, array $surfaces): array
    {
        $canonical = array_values(array_filter(Surface::cases(), static fn (Surface $case): bool => in_array($case, $surfaces, true)));

        if ($canonical !== $surfaces) {
            throw new self(sprintf(
                'Action "%s" lists the surfaces %s. Each surface is listed once, in the order %s.',
                $action,
                $surfaces === [] ? 'none' : implode(', ', array_map(static fn (Surface $surface): string => $surface->value, $surfaces)),
                implode(', ', array_map(static fn (Surface $case): string => $case->value, Surface::cases())),
            ));
        }

        return $surfaces;
    }

    /**
     * The events of a subscriber, at least one, each class once and sorted by class without case,
     * as #[Subscription] gives them, so an entry has one form and the same entries give the same
     * bytes.
     *
     * @param  list<SubscribedEvent>  $events
     * @return list<SubscribedEvent>
     */
    public static function checkEvents(string $subscriber, array $events): array
    {
        if ($events === []) {
            throw new self(sprintf('Subscriber "%s" receives no event. A subscriber receives at least one.', $subscriber));
        }

        $classes = array_map(static fn (SubscribedEvent $event): string => strtolower($event->class), $events);
        $canonical = array_values(array_unique($classes));
        sort($canonical, SORT_STRING);

        if ($canonical !== $classes) {
            throw new self(sprintf(
                'Subscriber "%s" receives the events %s. Each event class is listed once, sorted by class.',
                $subscriber,
                implode(', ', array_map(static fn (SubscribedEvent $event): string => $event->class, $events)),
            ));
        }

        return $events;
    }

    public static function because(string $message): self
    {
        return new self($message);
    }
}
