<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Bindings\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;

/**
 * Reads which class implements a contract from the configuration (GUARDRAILS 2.3: contracts are
 * bound in the container and can be overridden in the configuration).
 *
 * The map is `cbox-cms.contracts`, contract => implementation. The core's config/cbox-cms.php has the
 * defaults, and an application overrides one entry at a time.
 */
#[Internal]
final readonly class ContractBindings
{
    public const string CONFIG_KEY = 'cbox-cms.contracts';

    public function __construct(private Repository $config) {}

    /**
     * Builds the configured implementation of the contract from the container.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    public function resolve(Container $container, string $contract): object
    {
        $implementation = $this->implementationOf($contract);
        $instance = $container->make($implementation);

        if (! $instance instanceof $contract) {
            throw new InvalidContractBinding(sprintf(
                'The container built [%s] for [%s], which does not implement it.',
                get_debug_type($instance),
                $contract,
            ));
        }

        return $instance;
    }

    /**
     * The configured class that implements the contract.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return class-string<T>
     */
    public function implementationOf(string $contract): string
    {
        $key = self::CONFIG_KEY.'.'.$contract;
        $implementation = $this->config->get($key);

        if (! is_string($implementation) || $implementation === '') {
            throw new InvalidContractBinding(sprintf(
                'No implementation of [%s] is configured. Set [%s] to a class that implements it.',
                $contract,
                $key,
            ));
        }

        if (! class_exists($implementation)) {
            throw new InvalidContractBinding(sprintf(
                'The implementation [%s] configured in [%s] is not a class.',
                $implementation,
                $key,
            ));
        }

        if (! is_a($implementation, $contract, true)) {
            throw new InvalidContractBinding(sprintf(
                'The class [%s] configured in [%s] does not implement [%s].',
                $implementation,
                $key,
                $contract,
            ));
        }

        if (! new ReflectionClass($implementation)->isInstantiable()) {
            throw new InvalidContractBinding(sprintf(
                'The class [%s] configured in [%s] cannot be instantiated.',
                $implementation,
                $key,
            ));
        }

        return $implementation;
    }
}
