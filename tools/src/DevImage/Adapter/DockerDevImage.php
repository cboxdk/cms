<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Adapter;

use Cbox\Cms\Tooling\DevImage\Boundary\ComposePsJson;
use Cbox\Cms\Tooling\DevImage\Domain\CheckoutVolume;
use Cbox\Cms\Tooling\DevImage\Domain\DevImageRun;
use Cbox\Cms\Tooling\DevImage\Domain\DevImageTarget;
use Cbox\Cms\Tooling\DevImage\Domain\PublishedPort;
use Cbox\Cms\Tooling\DevImage\Domain\SharedServices;
use Cbox\Cms\Tooling\Services\Boundary\CurrentHostUser;
use Cbox\Cms\Tooling\Services\Boundary\GitCheckout;
use Cbox\Cms\Tooling\Services\Domain\Checkout;
use Cbox\Cms\Tooling\Services\Domain\ServicesPlan;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * Runs a command for the checkout of a directory in the dev image, from the host (DevImageRun).
 * First it asks docker compose, with the main checkout's compose.yaml, whether the shared
 * services run and are healthy, and fails with the fix when they are not; it never starts or
 * recreates them. Then it makes the checkout's volumes that are missing and hands them to the host
 * user, and runs the container with the terminal's standard input, output and error,
 * so the command's output streams as it runs. Returns the command's exit code, or 1 when the run
 * cannot start, with the reason on standard error.
 */
final readonly class DockerDevImage
{
    /**
     * @param  resource  $errors
     */
    public function __construct(private mixed $errors = STDERR) {}

    /**
     * @param  list<string>  $command
     * @param  list<string>  $extraMounts  directories outside the checkout the command writes to
     * @param  PublishedPort|null  $port  the container's port to publish on the host, for a command that serves
     */
    public function run(string $directory, array $command, array $extraMounts = [], ?PublishedPort $port = null): int
    {
        try {
            $checkout = GitCheckout::resolve($directory);
            $user = CurrentHostUser::read();
            $services = $this->services($checkout);

            if (! $services->ready() || $services->network === null) {
                throw new UnexpectedValueException((string) $services->problem);
            }

            $interactive = stream_isatty(STDIN) && stream_isatty(STDOUT);
            $target = new DevImageTarget(
                $checkout,
                $user,
                (string) gethostname(),
                $this->pestDirectory(),
                $services->network,
                $extraMounts,
                $interactive,
                $this->passedEnvironment(),
                $command,
                $port,
            );
            $this->claimVolume($target);
        } catch (UnexpectedValueException|InvalidArgumentException $exception) {
            fwrite($this->errors, $exception->getMessage()."\n");

            return 1;
        }

        $process = proc_open(DevImageRun::command($target), [STDIN, STDOUT, STDERR], $pipes);

        if ($process === false) {
            fwrite($this->errors, "Cannot start docker run for the dev image.\n");

            return 1;
        }

        return proc_close($process);
    }

    private function services(Checkout $checkout): SharedServices
    {
        $process = new Process(
            ['docker', 'compose', '--file', $checkout->mainRoot.'/'.ServicesPlan::COMPOSE_FILE, '--project-directory', $checkout->mainRoot, 'ps', '--all', '--format', 'json', ...ServicesPlan::SHARED_SERVICES],
            $checkout->root,
            null,
            null,
            120,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $reason = trim($process->getErrorOutput().' '.$process->getOutput());

            return SharedServices::unknown($reason === '' ? 'it exited '.($process->getExitCode() ?? 'without an exit code').'.' : $reason, $checkout->mainRoot);
        }

        return SharedServices::of(ComposePsJson::decode($process->getOutput()), $checkout->mainRoot);
    }

    /**
     * Makes the checkout's volumes that do not exist yet and hands them to the host user.
     */
    private function claimVolume(DevImageTarget $target): void
    {
        $missing = [];

        foreach (CheckoutVolume::all($target->checkout->root) as $volume) {
            $inspect = new Process(['docker', 'volume', 'inspect', $volume->name], null, null, null, 120);
            $inspect->run();

            if ($inspect->isSuccessful()) {
                continue;
            }

            $this->must(['docker', 'volume', 'create', '--label', CheckoutVolume::LABEL.'='.$volume->checkout, $volume->name], $volume->name);
            $missing[] = $volume;
        }

        if ($missing !== []) {
            $this->must(DevImageRun::claimVolumes($missing, $target), implode(', ', array_map(static fn (CheckoutVolume $volume): string => $volume->name, $missing)));
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function must(array $command, string $volumes): void
    {
        $process = new Process($command, null, null, null, 900);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new UnexpectedValueException("Cannot prepare the volumes {$volumes} for the dev image: ".trim($process->getErrorOutput().' '.$process->getOutput()));
        }
    }

    /**
     * The host's ~/.pest, where Pest keeps the graph of test impact analysis, made when missing.
     */
    private function pestDirectory(): string
    {
        $home = getenv('HOME');

        if (! is_string($home) || ! str_starts_with($home, '/')) {
            throw new UnexpectedValueException('HOME is not set to an absolute directory, so ~/.pest cannot be mounted into the dev image.');
        }

        $directory = rtrim($home, '/').'/.pest';

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new UnexpectedValueException("Cannot create {$directory} for the graph of Pest's test impact analysis.");
        }

        return (string) realpath($directory);
    }

    /**
     * @return array<string, string>
     */
    private function passedEnvironment(): array
    {
        $environment = [];

        foreach (DevImageRun::PASSED_VARIABLES as $variable) {
            $value = getenv($variable);

            if (is_string($value)) {
                $environment[$variable] = $value;
            }
        }

        return $environment;
    }
}
