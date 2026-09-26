<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\CiFiles;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Tag\TaggedValue;

/*
 * The CI of GUARDRAILS 10: .github/workflows/ci.yml, the entry script bin/ci, and
 * compose.ci.yaml, which runs bin/ci in a container because the repository has no remote. These
 * tests hold the three to each other, to compose.yaml and to the local profile, so CI runs the
 * same scripts, images and roles as a developer, and they guard the files as checks
 * (GUARDRAILS 7.3).
 */

const DECLARED_RUNNER = 'Declared runner: GitHub-hosted ubuntu-latest, 4 vCPU and 16 GB RAM';

/**
 * @return array<array-key, mixed>
 */
function workflowJob(): array
{
    $job = CiFiles::at(CiFiles::yaml(CiFiles::WORKFLOW), 'jobs', 'pr-profile');

    expect($job)->toBeArray();

    return is_array($job) ? $job : [];
}

/**
 * @return list<array<array-key, mixed>>
 */
function workflowSteps(): array
{
    $steps = CiFiles::at(workflowJob(), 'steps');

    expect($steps)->toBeArray()->toBeList();

    return is_array($steps) ? array_values(array_filter($steps, is_array(...))) : [];
}

it('runs on the declared runner, ubuntu-latest, with PHP 8.5 of the v1 channel and the services of compose.yaml', function (): void {
    $compose = CiFiles::yaml(CiFiles::COMPOSE);
    $job = workflowJob();

    expect(CiFiles::at($job, 'runs-on'))->toBe('ubuntu-latest')
        ->and(CiFiles::at($job, 'container', 'image'))->toBe('ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1')
        ->and(CiFiles::at($job, 'container', 'image'))->toBe(CiFiles::at($compose, 'services', 'php', 'image'))
        ->and(CiFiles::at($job, 'services', 'postgres', 'image'))->toBe('postgres:17')
        ->and(CiFiles::at($job, 'services', 'postgres', 'image'))->toBe(CiFiles::at($compose, 'services', 'postgres', 'image'))
        ->and(CiFiles::at($job, 'services', 'valkey', 'image'))->toBe(CiFiles::at($compose, 'services', 'valkey', 'image'))
        ->and(CiFiles::at($job, 'services', 'postgres', 'options'))->toContain('--health-cmd')
        ->and(CiFiles::at($job, 'services', 'valkey', 'options'))->toContain('--health-cmd')
        ->and(CiFiles::at($job, 'timeout-minutes'))->toBeInt()
        ->and(CiFiles::text(CiFiles::WORKFLOW))->toContain(DECLARED_RUNNER);
});

it('runs every pull request and has only setup steps besides bin/ci', function (): void {
    $workflow = CiFiles::yaml(CiFiles::WORKFLOW);
    $runs = [];
    $uses = [];

    foreach (workflowSteps() as $step) {
        $run = CiFiles::at($step, 'run');
        $action = CiFiles::at($step, 'uses');

        expect(is_string($run) xor is_string($action))->toBeTrue();

        if (is_string($run)) {
            $runs[] = $run;
        }

        if (is_string($action)) {
            $uses[] = preg_replace('/@.*$/', '', $action);
        }
    }

    // YAML 1.1 reads the key `on` as true.
    expect(CiFiles::at($workflow, 'on') ?? CiFiles::at($workflow, '1'))->toBeArray()->toHaveKey('pull_request')
        ->and($runs)->toBe(['docker/ci-setup.sh', 'bin/ci'])
        ->and($uses)->toBe(['actions/checkout', 'actions/upload-artifact'])
        ->and(CiFiles::at($workflow, 'permissions'))->toBe(['contents' => 'read']);
});

it('names the scripts of the local profile in both bin/ci and ci.yml, and bin/ci runs them only through composer check', function (): void {
    $scripts = CiFiles::profileScripts();
    $code = CiFiles::codeLines(CiFiles::ENTRY);

    expect($scripts)->toBe(['lint:check', 'format:check', 'rector:check', 'analyse', 'typecheck', 'lint', 'check:generated'])
        ->and(CiFiles::text(CiFiles::ENTRY))->toContain(...$scripts)
        ->and(CiFiles::text(CiFiles::WORKFLOW))->toContain(...$scripts)
        ->and(array_values(array_filter($code, static fn (string $line): bool => str_contains($line, 'composer check'))))
        ->toBe(['composer check -- --pr --report="$report" 2>&1 | tee "$log"']);

    foreach ($code as $line) {
        expect($line)->not->toContain('vendor/bin/')
            ->and($line)->not->toContain('npx ')
            ->and($line)->not->toContain('npm run');
    }
});

it('gives the job and the ci service the same environment, and the roles of compose.yaml', function (): void {
    $job = CiFiles::strings(workflowJob(), 'env');
    $service = CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci', 'environment');
    $postgres = CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE), 'services', 'postgres', 'environment');
    $roles = array_filter($postgres, static fn (string $key): bool => str_starts_with($key, 'CMS_'), ARRAY_FILTER_USE_KEY);

    unset($job['CMS_CI_RUNNER'], $service['CMS_CI_RUNNER'], $service['CI']);
    ksort($job);
    ksort($service);
    ksort($roles);

    expect($job)->toBe($service)
        ->and($roles)->toHaveCount(7)
        ->and(array_intersect_key($job, $roles))->toBe($roles)
        ->and($job['CMS_CI_POSTGRES_SUPERUSER'] ?? null)->toBe($postgres['POSTGRES_USER'] ?? null)
        ->and($job['CMS_CI_POSTGRES_PASSWORD'] ?? null)->toBe($postgres['POSTGRES_PASSWORD'] ?? null)
        ->and($job['CMS_CI_PROVISION_POSTGRES'] ?? null)->toBe('1')
        ->and($job['XDEBUG_MODE'] ?? null)->toBe('off');
});

it('keeps POSTGRES_* out of the environment bin/ci runs in, because Testbench copies them into the pgsql connection', function (): void {
    $job = CiFiles::strings(workflowJob(), 'env');
    $service = CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci', 'environment');

    foreach ([...array_keys($job), ...array_keys($service)] as $key) {
        expect($key)->not->toStartWith('POSTGRES_');
    }
});

it('builds the ci service from docker/ci.Dockerfile on the v1 PHP image, with the setup ci.yml runs', function (): void {
    $service = CiFiles::at(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci');
    $dockerfile = CiFiles::codeLines(CiFiles::DOCKERFILE);

    expect(CiFiles::at(is_array($service) ? $service : [], 'build'))->toBe(['context' => 'docker', 'dockerfile' => 'ci.Dockerfile'])
        ->and($dockerfile[0] ?? null)->toBe('FROM ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1')
        ->and($dockerfile)->toContain('RUN /usr/local/lib/cbox-ci/ci-setup.sh')
        ->and($dockerfile)->toContain('ENTRYPOINT ["/usr/local/lib/cbox-ci/ci-entry.sh"]')
        ->and(CiFiles::text('docker/ci-setup.sh'))->toContain('required_node_major=22');
});

it('runs the gates as the user ci that the setup creates, never as root, whose tests of file permissions skip', function (): void {
    $setup = CiFiles::codeLines('docker/ci-setup.sh');
    $entry = CiFiles::codeLines(CiFiles::ENTRY);

    expect(CiFiles::strings(workflowJob(), 'env')['CMS_CI_USER'] ?? null)->toBe('ci')
        ->and(CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci', 'environment')['CMS_CI_USER'] ?? null)->toBe('ci')
        ->and($setup)->toContain('useradd --uid 1001 --user-group --create-home --shell /bin/bash ci')
        ->and($entry)->toContain('if [[ $EUID -eq 0 ]]; then')
        ->and($entry)->toContain('exec setpriv --reuid="$CMS_CI_USER" --regid="$CMS_CI_USER" --init-groups \\');
});

it('runs the ci service on a read-only .git, next to compose.yaml\'s services without host ports and with empty data', function (): void {
    $compose = CiFiles::yaml(CiFiles::COMPOSE_CI);

    expect(CiFiles::at($compose, 'name'))->toBe('laravel-cms-ci')
        ->and(CiFiles::at($compose, 'services', 'ci', 'volumes'))->toBe(['${CMS_CI_SOURCE_GIT:-./.git}:/source.git:ro'])
        ->and(CiFiles::at($compose, 'services', 'ci', 'depends_on'))->toBe([
            'postgres' => ['condition' => 'service_healthy'],
            'valkey' => ['condition' => 'service_healthy'],
        ])
        ->and(CiFiles::text(CiFiles::COMPOSE_CI))->toContain(DECLARED_RUNNER);

    foreach (['postgres' => '/var/lib/postgresql/data', 'valkey' => '/data'] as $name => $data) {
        $ports = CiFiles::at($compose, 'services', $name, 'ports');

        expect(CiFiles::at($compose, 'services', $name, 'extends'))->toBe(['file' => 'compose.yaml', 'service' => $name])
            ->and($ports)->toBeInstanceOf(TaggedValue::class)
            ->and($ports instanceof TaggedValue ? [$ports->getTag(), $ports->getValue()] : null)->toBe(['reset', []])
            ->and(CiFiles::at($compose, 'services', $name, 'volumes'))->toBe([['type' => 'tmpfs', 'target' => $data]]);
    }

    foreach (['ci', 'postgres', 'valkey'] as $name) {
        expect(CiFiles::at($compose, 'services', $name, 'cpuset'))->toBe('0-3');
    }
});

it('keeps bin/ci and the docker scripts executable in git', function (string $file): void {
    $process = new Process(['git', 'ls-files', '--stage', '--', $file], Phpstan::root());
    $process->mustRun();

    expect($process->getOutput())->toStartWith('100755 ');
})->with(['bin/ci', 'docker/ci-setup.sh', 'docker/ci-entry.sh', 'docker/postgres/initdb.d/10-cms.sh']);
