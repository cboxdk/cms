<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Process;

use Closure;
use InvalidArgumentException;

/**
 * Runs a closure in the environment of another process and puts the old one back afterwards.
 * Laravel reads an environment variable from $_SERVER, $_ENV and getenv(), and artisan its command
 * from $_SERVER['argv'], so this is how a test acts as a process that serves HTTP
 * (APP_RUNNING_IN_CONSOLE=false), an Octane worker (LARAVEL_OCTANE), a queue worker (argv), or a
 * process whose environment declares it the maintenance process or leaves that out. A variable is
 * set, or removed with null, in all three places; argv only in $_SERVER.
 */
final class ProcessEnvironment
{
    /**
     * @template T
     *
     * @param  array<array-key, mixed>  $variables  a name and a string, a list of strings for argv, or null
     * @param  Closure(): T  $run
     * @return T
     */
    public static function during(array $variables, Closure $run): mixed
    {
        $saved = [];

        foreach ($variables as $name => $value) {
            if (! is_string($name) || ! ($value === null || is_string($value) || (is_array($value) && array_is_list($value)))) {
                throw new InvalidArgumentException(sprintf('Give %s a string, a list of strings or null.', $name));
            }

            $local = getenv($name, true);

            $saved[$name] = [
                'server' => array_key_exists($name, $_SERVER) ? [$_SERVER[$name]] : [],
                'env' => array_key_exists($name, $_ENV) ? [$_ENV[$name]] : [],
                'getenv' => $local === false ? [] : [$local],
            ];

            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);

                continue;
            }

            $_SERVER[$name] = $value;

            if (is_string($value)) {
                $_ENV[$name] = $value;
                putenv($name.'='.$value);
            }
        }

        try {
            return $run();
        } finally {
            foreach ($saved as $name => $places) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);

                if ($places['server'] !== []) {
                    $_SERVER[$name] = $places['server'][0];
                }

                if ($places['env'] !== []) {
                    $_ENV[$name] = $places['env'][0];
                }

                if ($places['getenv'] !== []) {
                    putenv($name.'='.$places['getenv'][0]);
                }
            }
        }
    }
}
