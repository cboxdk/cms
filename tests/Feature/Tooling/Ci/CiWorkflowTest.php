<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\CiFiles;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Tag\TaggedValue;

/*
 * The CI of GUARDRAILS 10: .github/workflows/ci.yml, the entry script bin/ci, and
 * compose.ci.yaml, which runs the same bin/ci in a container on a developer's machine, before a
 * push to github.com/cboxdk/cms. These tests hold the three to each other, to compose.yaml and to the local profile, so CI runs the
 * same scripts, images and roles as a developer, and they guard the files as checks
 * (GUARDRAILS 7.3).
 */

const DECLARED_RUNNER = 'Declared runner: GitHub-hosted ubuntu-latest, 4 vCPU and 16 GB RAM';

/**
 * The jobs of ci.yml, by name: plan, gates, one per shard of mutation on changed files, and the
 * verdict over them (M1-T66).
 *
 * @var list<string>
 */
const WORKFLOW_JOBS = ['plan', 'gates', 'mutation', 'verdict'];

/**
 * The jobs that run the Pest suites against Postgres and Valkey, and so have the services.
 *
 * @var list<string>
 */
const SERVICE_JOBS = ['gates', 'mutation'];

/**
 * @return array<array-key, mixed>
 */
function workflowJob(string $name = 'gates'): array
{
    $job = CiFiles::at(CiFiles::yaml(CiFiles::WORKFLOW), 'jobs', $name);

    expect($job)->toBeArray();

    return is_array($job) ? $job : [];
}

/**
 * @return list<array<array-key, mixed>>
 */
function workflowSteps(string $job = 'gates'): array
{
    $steps = CiFiles::at(workflowJob($job), 'steps');

    expect($steps)->toBeArray()->toBeList();

    return is_array($steps) ? array_values(array_filter($steps, is_array(...))) : [];
}

/**
 * The environment every job shares, at the top of ci.yml.
 *
 * @return array<string, string>
 */
function workflowEnvironment(): array
{
    return CiFiles::strings(CiFiles::yaml(CiFiles::WORKFLOW), 'env');
}

it('runs every job on the declared runner, ubuntu-latest, with PHP 8.5 of the v1 channel, and the jobs with Pest on the services of compose.yaml', function (string $name): void {
    $compose = CiFiles::yaml(CiFiles::COMPOSE);
    $job = workflowJob($name);

    expect(CiFiles::at($job, 'runs-on'))->toBe('ubuntu-latest')
        ->and(CiFiles::at($job, 'container', 'image'))->toBe('ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1')
        ->and(CiFiles::at($job, 'container', 'image'))->toBe(CiFiles::at($compose, 'services', 'php', 'image'))
        ->and(CiFiles::at($job, 'timeout-minutes'))->toBeInt()
        ->and(CiFiles::text(CiFiles::WORKFLOW))->toContain(DECLARED_RUNNER);

    if (in_array($name, SERVICE_JOBS, true)) {
        expect(CiFiles::at($job, 'services', 'postgres', 'image'))->toBe('ghcr.io/cboxdk/postgres:18')
            ->and(CiFiles::at($job, 'services', 'postgres', 'image'))->toBe(CiFiles::at($compose, 'services', 'postgres', 'image'))
            ->and(CiFiles::at($job, 'services', 'valkey', 'image'))->toBe('ghcr.io/cboxdk/valkey:8')
            ->and(CiFiles::at($job, 'services', 'valkey', 'image'))->toBe(CiFiles::at($compose, 'services', 'valkey', 'image'));
    } else {
        expect(array_keys($job))->not->toContain('services');
    }
})->with(WORKFLOW_JOBS);

it('has exactly the jobs plan, gates, mutation and verdict', function (): void {
    $jobs = CiFiles::at(CiFiles::yaml(CiFiles::WORKFLOW), 'jobs');

    expect(is_array($jobs) ? array_keys($jobs) : null)->toBe(WORKFLOW_JOBS);
});

it('checks the health of the services as compose.yaml does, with the readiness file of cbox-init, and mounts nothing into them', function (string $job, string $name): void {
    $test = CiFiles::at(CiFiles::yaml(CiFiles::COMPOSE), 'services', $name, 'healthcheck', 'test');
    $service = CiFiles::at(workflowJob($job), 'services', $name);

    expect($test)->toBe(['CMD', 'test', '-f', '/tmp/cbox-ready'])
        ->and(CiFiles::at(is_array($service) ? $service : [], 'options'))->toBeString()
        ->toContain('--health-cmd "test -f /tmp/cbox-ready"')
        ->and(is_array($service) ? array_keys($service) : null)->not->toContain('volumes');
})->with(SERVICE_JOBS)->with(['postgres', 'valkey']);

it('runs every pull request, push to main and run started by hand, and each job has only setup steps besides bin/ci', function (string $name, array $actions): void {
    $workflow = CiFiles::yaml(CiFiles::WORKFLOW);
    $runs = [];
    $uses = [];

    foreach (workflowSteps($name) as $step) {
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
    expect(CiFiles::at($workflow, 'on') ?? CiFiles::at($workflow, '1'))->toBeArray()->toHaveKey('pull_request')->toHaveKey('push')->toHaveKey('workflow_dispatch')
        ->and($runs)->toBe(['docker/ci-setup.sh', 'bin/ci'])
        ->and($uses)->toBe($actions)
        ->and(CiFiles::at($workflow, 'permissions'))->toBe(['contents' => 'read']);
})->with([
    'plan' => ['plan', ['actions/checkout', 'actions/upload-artifact']],
    'gates' => ['gates', ['actions/checkout', 'actions/upload-artifact']],
    'mutation' => ['mutation', ['actions/checkout', 'actions/upload-artifact']],
    'verdict' => ['verdict', ['actions/checkout', 'actions/download-artifact']],
]);

it('names each job\'s part of bin/ci in CMS_CI_PART, a shard of the plan\'s matrix for the mutation job', function (): void {
    expect(CiFiles::strings(workflowJob('plan'), 'env'))->toBe(['CMS_CI_PART' => 'plan'])
        ->and(CiFiles::strings(workflowJob('gates'), 'env'))->toBe(['CMS_CI_PART' => 'gates'])
        ->and(CiFiles::strings(workflowJob('mutation'), 'env'))->toBe(['CMS_CI_PART' => 'shard:${{ matrix.shard }}/${{ needs.plan.outputs.count }}'])
        ->and(CiFiles::strings(workflowJob('verdict'), 'env'))->toBe([
            'CMS_CI_PART' => 'verdict',
            'CMS_CI_ARTIFACTS' => 'build/ci/artifacts',
            'CMS_CI_GATES_RESULT' => '${{ needs.gates.result }}',
            'CMS_CI_SHARDS_RESULT' => '${{ needs.plan.result == \'success\' && needs.mutation.result || \'failure\' }}',
        ]);
});

it('runs a mutation job per shard of the plan, each to its end, and the verdict after every job, whatever they did', function (): void {
    $bin = CiFiles::stepWith(workflowSteps('plan'), 'run', 'bin/ci');
    $download = CiFiles::stepWith(workflowSteps('verdict'), 'uses', 'actions/download-artifact');

    expect(CiFiles::at(workflowJob('plan'), 'outputs'))->toBe(['count' => '${{ steps.ci.outputs.count }}', 'shards' => '${{ steps.ci.outputs.shards }}'])
        ->and(CiFiles::at($bin, 'id'))->toBe('ci')
        ->and(CiFiles::at(workflowJob('mutation'), 'needs'))->toBe('plan')
        ->and(CiFiles::at(workflowJob('mutation'), 'strategy'))->toBe(['fail-fast' => false, 'matrix' => ['shard' => '${{ fromJSON(needs.plan.outputs.shards) }}']])
        ->and(array_keys(workflowJob('gates')))->not->toContain('needs')
        ->and(CiFiles::at(workflowJob('verdict'), 'needs'))->toBe(['plan', 'gates', 'mutation'])
        ->and(CiFiles::at(workflowJob('verdict'), 'if'))->toBe('always()')
        ->and(CiFiles::at($download, 'with'))->toBe(['pattern' => 'mutation-*', 'path' => 'build/ci/artifacts']);
});

it('keeps the plan and each shard\'s report as artifacts the verdict fetches, named after the files the verdict reads', function (): void {
    $plan = CiFiles::stepWith(workflowSteps('plan'), 'uses', 'actions/upload-artifact');
    $shard = CiFiles::stepWith(workflowSteps('mutation'), 'uses', 'actions/upload-artifact');
    $gates = CiFiles::stepWith(workflowSteps('gates'), 'uses', 'actions/upload-artifact');

    expect(CiFiles::at($plan, 'with'))->toBe(['name' => 'mutation-plan', 'path' => 'build/ci/', 'if-no-files-found' => 'error'])
        ->and(CiFiles::at($shard, 'with'))->toBe(['name' => 'mutation-shard-${{ matrix.shard }}', 'path' => 'build/ci/', 'if-no-files-found' => 'warn'])
        ->and(CiFiles::at($shard, 'if'))->toBe('always()')
        ->and(CiFiles::at($gates, 'with', 'name'))->toBe('gate-report')
        ->and(CiFiles::codeLines(CiFiles::ENTRY))->toContain(
            'composer mutation:plan -- --output="$1/mutation-plan.json" --github-output="$outputs"',
            'check "$3/check.json" --shard="$1/$2" --mutation-report="$3/mutation-shard.json"',
            'composer mutation:verdict -- --plan="$1/mutation-plan/mutation-plan.json" --reports="$1" --gates="$2" --shards="$3" 2>&1 | tee "$1/verdict.log"',
        );
});

it('names the scripts of the local profile in both bin/ci and ci.yml, and bin/ci runs them only through composer check', function (): void {
    $scripts = CiFiles::profileScripts();
    $code = CiFiles::codeLines(CiFiles::ENTRY);

    expect($scripts)->toBe(['lint:check', 'format:check', 'rector:check', 'analyse', 'typecheck', 'lint', 'install:check', 'check:generated'])
        ->and(CiFiles::text(CiFiles::ENTRY))->toContain(...$scripts)
        ->and(CiFiles::text(CiFiles::WORKFLOW))->toContain(...$scripts)
        ->and(array_values(array_filter($code, static fn (string $line): bool => str_contains($line, 'composer check'))))
        ->toBe(['composer check -- --pr --report="$file" "$@" 2>&1 | tee "$(dirname "$file")/check.log"']);

    foreach ($code as $line) {
        expect($line)->not->toContain('vendor/bin/')
            ->and($line)->not->toContain('npx ')
            ->and($line)->not->toContain('npm run');
    }
});

it('gives every job and the ci service the same environment, and the roles of compose.yaml', function (): void {
    $job = workflowEnvironment();
    $service = CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci', 'environment');
    $postgres = CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE), 'services', 'postgres', 'environment');
    $roles = array_filter($postgres, static fn (string $key): bool => str_starts_with($key, 'CMS_'), ARRAY_FILTER_USE_KEY);

    // The runner and the base of the change differ by design; the next test pins the base. The
    // part is each job's own, and the host's in compose.ci.yaml.
    unset($job['CMS_CI_RUNNER'], $service['CMS_CI_RUNNER'], $service['CI'], $job['CMS_CI_BASE_REF'], $service['CMS_CI_BASE_REF'], $service['CMS_CI_PART']);
    ksort($job);
    ksort($service);
    ksort($roles);

    expect($job)->toBe($service)
        ->and($roles)->toHaveCount(7)
        ->and(array_intersect_key($job, $roles))->toBe($roles)
        ->and($job['CMS_CI_POSTGRES_SUPERUSER'] ?? null)->toBe($postgres['POSTGRES_USER'] ?? null)
        ->and($job['CMS_CI_POSTGRES_PASSWORD'] ?? null)->toBe($postgres['POSTGRES_PASSWORD'] ?? null)
        ->and($job['CMS_CI_PROVISION_POSTGRES'] ?? null)->toBe('1')
        ->and($job['XDEBUG_MODE'] ?? null)->toBe('off')
        ->and(CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci', 'environment')['CMS_CI_PART'] ?? null)->toBe('${CMS_CI_PART:-}');
});

it('gives mutation on changed files its base: the pull request\'s base commit in ci.yml with the whole history in every job, the host\'s CMS_CI_BASE_REF in compose.ci.yaml', function (string $name): void {
    $checkout = array_values(array_filter(workflowSteps($name), static fn (array $step): bool => is_string(CiFiles::at($step, 'uses')) && str_starts_with(CiFiles::at($step, 'uses'), 'actions/checkout@')));

    expect(workflowEnvironment()['CMS_CI_BASE_REF'] ?? null)->toBe('${{ inputs.base_ref || github.event.pull_request.base.sha || github.event.before }}')
        ->and(CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci', 'environment')['CMS_CI_BASE_REF'] ?? null)->toBe('${CMS_CI_BASE_REF:-}')
        ->and($checkout)->toHaveCount(1)
        ->and(CiFiles::at($checkout[0] ?? [], 'with', 'fetch-depth'))->toBe(0);

    foreach ([CiFiles::ENTRY, CiFiles::WORKFLOW, CiFiles::COMPOSE_CI, 'docker/ci-entry.sh', 'CLAUDE.md', 'AGENTS.md'] as $file) {
        expect(CiFiles::text($file))->toContain('CMS_CI_BASE_REF');
    }
})->with(WORKFLOW_JOBS);

it('takes the base of a run started by hand from its required input base_ref, in a concurrency group of its own', function (): void {
    $workflow = CiFiles::yaml(CiFiles::WORKFLOW);
    $on = CiFiles::at($workflow, 'on') ?? CiFiles::at($workflow, '1');
    $inputs = is_array($on) ? CiFiles::at($on, 'workflow_dispatch', 'inputs') : null;

    expect(is_array($inputs) ? array_keys($inputs) : null)->toBe(['base_ref'])
        ->and(is_array($on) ? CiFiles::strings($on, 'workflow_dispatch', 'inputs', 'base_ref') : [])->toBe([
            'description' => 'The base of the change that mutation on changed files mutates: a commit, or a ref of the checkout such as origin/main',
            'required' => 'true',
            'type' => 'string',
        ])
        ->and(workflowEnvironment()['CMS_CI_BASE_REF'] ?? '')->toStartWith('${{ inputs.base_ref || ')
        ->and(CiFiles::at($workflow, 'concurrency', 'group'))->toBe('ci-${{ github.event_name }}-${{ github.ref }}')
        ->and(preg_match('/run:.*inputs\\./', CiFiles::text(CiFiles::WORKFLOW)))->toBe(0);
});

it('names mutation on changed files as a step CI runs in gate 5, with a minimum score of 80, and never as not run', function (): void {
    expect(CiFiles::text(CiFiles::ENTRY))->toContain('vendor/bin/pest --mutate --everything --path=<files>, minimum score 80', 'gate 5  vendor/bin/pest --testsuite=Mutation')
        ->and(CiFiles::text(CiFiles::WORKFLOW))->toContain('mutation on changed files: Pest\'s --mutate with PCOV and a minimum score of 80 for each class')
        ->and(CiFiles::text(CiFiles::ENTRY))->not->toContain('and mutation on changed')
        ->and(CiFiles::text(CiFiles::WORKFLOW))->not->toContain('11 and mutation');
});

it('keeps POSTGRES_* out of the environment bin/ci runs in, because Testbench copies them into the pgsql connection', function (): void {
    $job = workflowEnvironment();
    $service = CiFiles::strings(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci', 'environment');
    $jobs = array_merge(...array_map(static fn (string $name): array => array_keys(CiFiles::strings(workflowJob($name), 'env')), WORKFLOW_JOBS));

    foreach ([...array_keys($job), ...array_keys($service), ...$jobs] as $key) {
        expect($key)->not->toStartWith('POSTGRES_');
    }
});

it('builds the ci service from docker/ci.Dockerfile on the v1 PHP image, adding only the entry point', function (): void {
    $service = CiFiles::at(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci');

    expect(CiFiles::at(is_array($service) ? $service : [], 'build'))->toBe(['context' => 'docker', 'dockerfile' => 'ci.Dockerfile'])
        ->and(CiFiles::codeLines(CiFiles::DOCKERFILE))->toBe([
            'FROM ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1',
            'COPY ci-entry.sh /usr/local/lib/cbox-ci/',
            'ENTRYPOINT ["/usr/local/lib/cbox-ci/ci-entry.sh"]',
        ])
        ->and(CiFiles::text('docker/ci-setup.sh'))->toContain('required_node_major=22');
});

it('runs the same setup script in ci.yml and compose.ci.yaml: HEAD\'s docker/ci-setup.sh in the checkout, then bin/ci', function (string $name): void {
    $runs = array_values(array_filter(array_map(static fn (array $step): mixed => CiFiles::at($step, 'run'), workflowSteps($name)), is_string(...)));
    $service = CiFiles::at(CiFiles::yaml(CiFiles::COMPOSE_CI), 'services', 'ci');
    $entry = CiFiles::codeLines('docker/ci-entry.sh');
    $default = array_search('if [[ $# -eq 0 ]]; then', $entry, true);

    // The job runs both after its checkout; the ci service's entry point runs both, in the same
    // order, from the archive of HEAD when it is given no command, as compose.ci.yaml gives none.
    expect($runs)->toBe(['docker/ci-setup.sh', 'bin/ci'])
        ->and(is_array($service) ? array_keys($service) : [])->not->toContain('command')
        ->and(is_array($service) ? array_keys($service) : [])->not->toContain('entrypoint')
        ->and(CiFiles::codeLines(CiFiles::DOCKERFILE))->toContain('ENTRYPOINT ["/usr/local/lib/cbox-ci/ci-entry.sh"]')
        ->and($default)->toBeInt()
        ->and(array_slice($entry, is_int($default) ? $default : 0, 4))->toBe(['if [[ $# -eq 0 ]]; then', 'docker/ci-setup.sh', 'set -- bin/ci', 'fi'])
        ->and(array_search('cd "$work"', $entry, true))->toBeLessThan(is_int($default) ? $default : 0)
        ->and(array_slice($entry, -1))->toBe(['exec "$@"']);
})->with(WORKFLOW_JOBS);

it('pins every action to the commit of a release tag, named in a comment', function (): void {
    $lines = array_values(array_filter(explode("\n", CiFiles::text(CiFiles::WORKFLOW)), static fn (string $line): bool => str_contains($line, 'uses:')));

    expect($lines)->toHaveCount(8);

    foreach ($lines as $line) {
        expect($line)->toMatch('/^\s+(- )?uses: [A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+@[0-9a-f]{40} # v\d+\.\d+\.\d+$/');
    }
});

it('stops the jobs that run Pest after 20 minutes, above their budget of 15, and the plan and the verdict after 10', function (): void {
    expect(CiFiles::at(workflowJob('gates'), 'timeout-minutes'))->toBe(20)
        ->and(CiFiles::at(workflowJob('mutation'), 'timeout-minutes'))->toBe(20)
        ->and(CiFiles::at(workflowJob('plan'), 'timeout-minutes'))->toBe(10)
        ->and(CiFiles::at(workflowJob('verdict'), 'timeout-minutes'))->toBe(10);
});

it('names the gates CI runs outside the local profile in bin/ci and ci.yml', function (): void {
    $bin = CiFiles::text(CiFiles::ENTRY);

    expect($bin)->toContain('gate 8  vendor/bin/pest --testsuite=Browser', 'gate 9  composer audit --locked --abandoned=report, npm audit', 'gate 10 composer docs:check', 'gates 7 and 11')
        ->and(CiFiles::text(CiFiles::WORKFLOW))->toContain('gate 8, the Browser suite', 'gate 9, composer audit and npm audit', 'gate 10, composer docs:check', 'gates 7 and 11')
        ->and($bin)->not->toContain('gates 7, 10 and 11')
        ->and(CiFiles::text(CiFiles::WORKFLOW))->not->toContain('gates 7, 10 and 11');
});

it('runs the gates as the user ci that the setup creates, never as root, whose tests of file permissions skip', function (): void {
    $setup = CiFiles::codeLines('docker/ci-setup.sh');
    $entry = CiFiles::codeLines(CiFiles::ENTRY);

    expect(workflowEnvironment()['CMS_CI_USER'] ?? null)->toBe('ci')
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

    // The data mounts of the cboxdk images. !override replaces compose.yaml's volumes instead of
    // merging them by target, so nothing else of the service's mounts reaches the run.
    foreach (['postgres' => '/var/lib/postgresql', 'valkey' => '/data'] as $name => $data) {
        $ports = CiFiles::at($compose, 'services', $name, 'ports');
        $volumes = CiFiles::at($compose, 'services', $name, 'volumes');

        expect(CiFiles::at($compose, 'services', $name, 'extends'))->toBe(['file' => 'compose.yaml', 'service' => $name])
            ->and($ports)->toBeInstanceOf(TaggedValue::class)
            ->and($ports instanceof TaggedValue ? [$ports->getTag(), $ports->getValue()] : null)->toBe(['reset', []])
            ->and($volumes)->toBeInstanceOf(TaggedValue::class)
            ->and($volumes instanceof TaggedValue ? [$volumes->getTag(), $volumes->getValue()] : null)
            ->toBe(['override', [['type' => 'tmpfs', 'target' => $data]]]);
    }

    foreach (['ci', 'postgres', 'valkey'] as $name) {
        expect(CiFiles::at($compose, 'services', $name, 'cpuset'))->toBe('0-3');
    }
});

it('mounts nothing from the working tree into the ci run\'s postgres, so bin/ci provisions it from HEAD\'s archive', function (): void {
    $compose = CiFiles::yaml(CiFiles::COMPOSE_CI);
    $volumes = CiFiles::at($compose, 'services', 'postgres', 'volumes');
    $mounts = $volumes instanceof TaggedValue && $volumes->getTag() === 'override' ? $volumes->getValue() : null;

    // Without !override, compose merges the bind mounts of compose.yaml's postgres into the run.
    expect(is_array($mounts) && $mounts !== [])->toBeTrue();

    foreach (is_array($mounts) ? $mounts : [] as $mount) {
        $text = is_string($mount) ? $mount : json_encode($mount, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        expect(is_array($mount) ? $mount['type'] ?? null : 'short syntax')->toBe('tmpfs')
            ->and($text)->not->toContain('docker/postgres/initdb.d')
            ->and($text)->not->toContain('docker-entrypoint-initdb.d');
    }

    expect(CiFiles::strings($compose, 'services', 'ci', 'environment')['CMS_CI_PROVISION_POSTGRES'] ?? null)->toBe('1')
        ->and(CiFiles::codeLines(CiFiles::ENTRY))->toContain('CMS_INIT_PGHOST="$DB_HOST" PGPORT="$DB_PORT" CMS_INIT_SQL_DIR=docker/postgres/sql \\');
});

it('keeps bin/ci and the docker scripts executable in git', function (string $file): void {
    $process = new Process(['git', 'ls-files', '--stage', '--', $file], Phpstan::root());
    $process->mustRun();

    expect($process->getOutput())->toStartWith('100755 ');
})->with(['bin/ci', 'docker/ci-setup.sh', 'docker/ci-entry.sh', 'docker/postgres/initdb.d/10-cms.sh']);
