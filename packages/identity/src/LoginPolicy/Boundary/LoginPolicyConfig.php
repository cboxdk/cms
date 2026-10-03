<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\LoginPolicy\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationContext;
use Cbox\Cms\Contracts\Identity\Login\AuthenticationMethod;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\ClassPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\LoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\Dto\SessionLifetimes;
use Cbox\Cms\Identity\LoginPolicy\Domain\InvalidLoginPolicy;
use Cbox\Cms\Identity\LoginPolicy\Domain\LocalFactors;
use Cbox\Cms\Identity\LoginPolicy\Domain\LoginMethod;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the login policy of this environment from `cbox-cms.identity.policy` (PRD 5.16), as
 * packages/identity/config/identity.php and docs/security/login-policy.md describe it. Every key
 * must be there in its form, or it throws InvalidLoginPolicy naming the key.
 *
 * `connections` and `methods` are maps from a name to whether the class may use it, so an
 * application that switches one on or off in its own configuration keeps the others, and a method
 * the map does not name is not allowed.
 */
#[Internal]
final readonly class LoginPolicyConfig
{
    public const string KEY = 'cbox-cms.identity.policy';

    /**
     * @throws InvalidLoginPolicy when a key is not in its form, or the policy may not hold in the environment
     */
    public static function read(Repository $config, string $environment): LoginPolicy
    {
        $policy = $config->get(self::KEY);

        if (! is_array($policy)) {
            throw InvalidLoginPolicy::key(self::KEY, 'a map with authoritative_connections, staff and end_user');
        }

        $read = new LoginPolicy(
            self::classPolicy($policy, 'staff'),
            self::classPolicy($policy, 'end_user'),
            self::connections(self::list($policy, 'authoritative_connections', self::KEY), self::KEY.'.authoritative_connections'),
        );
        $read->assertAllowedIn($environment);

        return $read;
    }

    /**
     * @param  array<array-key, mixed>  $policy
     *
     * @throws InvalidLoginPolicy
     */
    private static function classPolicy(array $policy, string $class): ClassPolicy
    {
        $key = self::KEY.'.'.$class;
        $values = $policy[$class] ?? null;

        if (! is_array($values)) {
            throw InvalidLoginPolicy::key($key, 'a map of the class\'s login policy');
        }

        $localLogin = $values['local_login'] ?? null;

        if (! is_bool($localLogin)) {
            throw InvalidLoginPolicy::key($key.'.local_login', 'true or false');
        }

        $factors = $values['local_factors'] ?? null;
        $localFactors = is_string($factors) ? LocalFactors::tryFrom($factors) : null;

        if (! $localFactors instanceof LocalFactors) {
            throw InvalidLoginPolicy::key($key.'.local_factors', 'password or passkey_or_two_factors');
        }

        $methods = [];

        foreach (self::switchedOn($values, 'methods', $key) as $name) {
            $method = LoginMethod::tryFrom($name);

            if (! $method instanceof LoginMethod) {
                throw InvalidLoginPolicy::key($key.'.methods', 'a map from a login method ('.implode(', ', array_map(static fn (LoginMethod $method): string => $method->value, LoginMethod::cases())).') to true or false');
            }

            $methods[] = $method;
        }

        try {
            $amr = array_map(static fn (string $value): AuthenticationMethod => new AuthenticationMethod($value), self::list($values, 'federated_amr', $key));
            $acr = array_map(static fn (string $value): AuthenticationContext => new AuthenticationContext($value), self::list($values, 'federated_acr', $key));
        } catch (InvalidIdentity) {
            throw InvalidLoginPolicy::key($key.'.federated_amr and federated_acr', 'lists of amr and acr values in their forms');
        }

        return new ClassPolicy(
            self::connections(self::switchedOn($values, 'connections', $key), $key.'.connections'),
            $methods,
            $localLogin,
            $localFactors,
            $amr,
            $acr,
            new SessionLifetimes(self::minutes($values, 'inactivity_minutes', $key), self::minutes($values, 'absolute_minutes', $key), $key),
            $key,
        );
    }

    /**
     * The names a map switches on, in the map's order.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     *
     * @throws InvalidLoginPolicy
     */
    private static function switchedOn(array $values, string $name, string $key): array
    {
        $map = $values[$name] ?? null;

        if (! is_array($map) || ($map !== [] && array_is_list($map))) {
            throw InvalidLoginPolicy::key($key.'.'.$name, 'a map from a name to true or false');
        }

        $on = [];

        foreach ($map as $entry => $allowed) {
            if (! is_string($entry) || ! is_bool($allowed)) {
                throw InvalidLoginPolicy::key($key.'.'.$name, 'a map from a name to true or false');
            }

            if ($allowed) {
                $on[] = $entry;
            }
        }

        return $on;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     *
     * @throws InvalidLoginPolicy
     */
    private static function list(array $values, string $name, string $key): array
    {
        $list = $values[$name] ?? null;

        if (! is_array($list) || ! array_is_list($list) || ! array_all($list, static fn (mixed $value): bool => is_string($value))) {
            throw InvalidLoginPolicy::key($key.'.'.$name, 'a list of strings');
        }

        /** @var list<string> $list */
        return $list;
    }

    /**
     * @param  list<string>  $names
     * @return list<ConnectionId>
     *
     * @throws InvalidLoginPolicy
     */
    private static function connections(array $names, string $key): array
    {
        try {
            return array_map(static fn (string $name): ConnectionId => new ConnectionId($name), $names);
        } catch (InvalidIdentity) {
            throw InvalidLoginPolicy::key($key, 'connection names: a lowercase letter, then at most 63 lowercase letters, digits, hyphens and underscores');
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     *
     * @throws InvalidLoginPolicy
     */
    private static function minutes(array $values, string $name, string $key): int
    {
        $minutes = $values[$name] ?? null;

        if (! is_int($minutes) || $minutes < 1) {
            throw InvalidLoginPolicy::key($key.'.'.$name, 'a whole number of minutes, 1 or more');
        }

        return $minutes;
    }
}
