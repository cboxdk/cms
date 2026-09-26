export const meta = {
  name: 'cms-milestone',
  description: 'Build one Cbox CMS block from MILESTONES.md: plan, build independent tasks in parallel worktrees, integrate through a merge queue that runs every gate, adversarial review, exit criteria, PROGRESS.md',
  whenToUse: 'Autopilot development of laravel-cms. Pass args {block: "M1"} (ids in PROGRESS.md). Optional: maxParallel (default 3; 1 builds one task at a time).',
  phases: [
    { title: 'Plan', detail: 'break the block into small tasks with a dependency graph, acceptance checks and exit criteria' },
    { title: 'Build', detail: 'independent tasks in parallel, each in its own worktree with its own test database; verify and fix there' },
    { title: 'Integrate', detail: 'one task at a time: rebase on main, run every gate on the result, fast-forward main' },
    { title: 'Review', detail: 'block-wide regression gate, lens reviewers, two skeptics per finding, fixes' },
    { title: 'Exit', detail: 'run the exit criteria and record the result in PROGRESS.md' },
  ],
}

const BLOCK = args && args.block
if (!BLOCK) {
  throw new Error('cms-milestone needs args {block: "<id>"}, e.g. {block: "M1"}')
}
const MAX_PARALLEL = Math.max(1, (args && args.maxParallel) || 3)
const MAX_FIX_ROUNDS = (args && args.maxFixRounds) || 3
const MAX_INTEGRATION_ROUNDS = (args && args.maxIntegrationRounds) || 2
const MAX_REVIEW_ROUNDS = (args && args.maxReviewRounds) || 2

const REPO = '/Users/sylvester/Projects/laravel-cms'
const WT_ROOT = '/Users/sylvester/Projects/laravel-cms-worktrees'
const PLAN_REPO = '/Users/sylvester/Projects/cbox-cms'
const CONTEXT = `The main checkout is ${REPO} (git repo, local only, never push, never add a remote). Planning documents: ${PLAN_REPO}/PRD.md (Danish, architecture), ${PLAN_REPO}/GUARDRAILS.md (coding rules), ${PLAN_REPO}/MILESTONES.md (build order and exit criteria). Working state: ${REPO}/PROGRESS.md; read "Beslutninger fra Sylvester" there. The current block is ${BLOCK} as listed in PROGRESS.md and described in MILESTONES.md. Never change the cboxdk ecosystem repos.`

const wtOf = id => `${WT_ROOT}/${BLOCK}-${id}`
const branchOf = id => `wip/${BLOCK}-${id}`

const WORKTREE_RULES = `Worktree rules:
- Work only in your worktree. Never edit files in the main checkout ${REPO}, and never commit on main there.
- The testkit gives every checkout its own Postgres test database and Valkey prefix, so the Postgres and Valkey suites and "composer check" can run next to other worktrees. The shared services run from the main checkout; if they are down, run "composer services:up" in ${REPO}, never "docker compose up" in a worktree.
- Do not run "composer check:selftest" or the CI container run (compose.ci.yaml); the integration step runs those one at a time.
- Do not edit PROGRESS.md. Report interpretations, items for human review and blockers in your result instead.`

const TASK = {
  type: 'object',
  properties: {
    id: { type: 'string' },
    title: { type: 'string' },
    goal: { type: 'string' },
    prdRefs: { type: 'array', items: { type: 'string' } },
    acceptance: { type: 'array', items: { type: 'string' } },
    dependsOn: { type: 'array', items: { type: 'string' } },
    touches: { type: 'array', items: { type: 'string' }, description: 'paths or areas the task expects to change' },
  },
  required: ['id', 'title', 'goal', 'acceptance', 'dependsOn'],
}
const PLAN = {
  type: 'object',
  properties: {
    block: { type: 'string' },
    summary: { type: 'string' },
    tasks: { type: 'array', items: TASK },
    exitCriteria: {
      type: 'array',
      items: {
        type: 'object',
        properties: { id: { type: 'string' }, description: { type: 'string' }, command: { type: 'string' } },
        required: ['id', 'description'],
      },
    },
    blockers: { type: 'array', items: { type: 'string' } },
  },
  required: ['block', 'summary', 'tasks', 'exitCriteria'],
}
const CRITIQUE = {
  type: 'object',
  properties: {
    missing: { type: 'array', items: { type: 'string' } },
    problems: { type: 'array', items: { type: 'string' } },
  },
  required: ['missing', 'problems'],
}
const TASK_RESULT = {
  type: 'object',
  properties: {
    status: { type: 'string', enum: ['done', 'failed', 'blocked'] },
    commit: { type: 'string' },
    summary: { type: 'string' },
    checksRun: { type: 'array', items: { type: 'string' } },
    blocker: { type: 'string' },
    interpretations: { type: 'array', items: { type: 'string' } },
    forHumanReview: { type: 'array', items: { type: 'string' } },
  },
  required: ['status', 'summary'],
}
const VERIFY = {
  type: 'object',
  properties: {
    pass: { type: 'boolean' },
    failures: { type: 'array', items: { type: 'string' } },
    checksRun: { type: 'array', items: { type: 'string' } },
  },
  required: ['pass', 'failures', 'checksRun'],
}
const INTEGRATION = {
  type: 'object',
  properties: {
    merged: { type: 'boolean' },
    mainHead: { type: 'string' },
    conflicts: { type: 'array', items: { type: 'string' } },
    failures: { type: 'array', items: { type: 'string' } },
    checksRun: { type: 'array', items: { type: 'string' } },
    wallTimeSeconds: { type: 'integer' },
  },
  required: ['merged', 'failures', 'checksRun'],
}
const FINDINGS = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          title: { type: 'string' },
          severity: { type: 'string', enum: ['blocker', 'high', 'medium', 'low'] },
          file: { type: 'string' },
          line: { type: 'integer' },
          problem: { type: 'string' },
          fix: { type: 'string' },
        },
        required: ['title', 'severity', 'file', 'problem', 'fix'],
      },
    },
  },
  required: ['findings'],
}
const VERDICT = {
  type: 'object',
  properties: { refuted: { type: 'boolean' }, reason: { type: 'string' } },
  required: ['refuted', 'reason'],
}
const EXIT = {
  type: 'object',
  properties: {
    criteria: {
      type: 'array',
      items: {
        type: 'object',
        properties: { id: { type: 'string' }, pass: { type: 'boolean' }, evidence: { type: 'string' } },
        required: ['id', 'pass', 'evidence'],
      },
    },
    allPass: { type: 'boolean' },
  },
  required: ['criteria', 'allPass'],
}
const PROGRESS_RESULT = {
  type: 'object',
  properties: {
    blockStatus: { type: 'string', enum: ['done', 'incomplete', 'blocked'] },
    nextBlock: { type: 'string' },
    commit: { type: 'string' },
  },
  required: ['blockStatus'],
}

const VERIFY_RULES = `Run the real checks in the worktree, do not assume: "composer check" and the task's acceptance items. Report pass only if every gate is green and every acceptance item is met. Do not change any files.`

// ---------------------------------------------------------------- Plan
phase('Plan')

// args.planFile reuses a plan from an earlier run of the same block (a JSON file with the PLAN shape) and skips planning.
const PLAN_FILE = args && args.planFile
let plan = PLAN_FILE ? await agent(
  `Read the JSON file ${PLAN_FILE} and return its content as the plan, exactly as it is: same tasks, ids, dependsOn, acceptance items and exit criteria. Do not change any files.`,
  { schema: PLAN, label: `load plan ${BLOCK}`, effort: 'low' },
) : await agent(
  `${CONTEXT}

Plan block ${BLOCK}. Read PROGRESS.md, the block in MILESTONES.md, the PRD sections it depends on, GUARDRAILS.md and the current repo state (git log, files). Work that is already committed and green counts as done; plan only what remains.

Break the remaining work into small tasks that one agent can finish, test and commit in one go. The tasks are built in parallel by up to ${MAX_PARALLEL} agents in separate worktrees, so dependsOn must be complete and exact: a task lists every task whose code or files it needs. Tasks without a dependency between them must be safe to build at the same time; when two tasks would change the same central file heavily (a schema, a shared config, the same class), make one depend on the other. Fill touches with the paths or areas each task changes. Every task needs concrete acceptance items that can be checked by running something (a test, a command, a query), not opinions.

Copy the block's exit criteria from MILESTONES.md into exitCriteria, each with a command or procedure that proves it.

Put decisions reserved for Sylvester (see CLAUDE.md) in blockers, and plan around them where possible. Do not change any files.`,
  { schema: PLAN, label: `plan ${BLOCK}`, effort: 'high' },
)

const critique = PLAN_FILE ? null : await agent(
  `${CONTEXT}

Here is a plan for block ${BLOCK}:
${JSON.stringify(plan, null, 2)}

Act as a completeness critic. Compare it with the block in MILESTONES.md, the PRD sections it builds, and GUARDRAILS.md (actions, DTOs, contracts with fakes and shared contract tests, real Postgres tests, observability, no placeholders, definition of done). List what is missing (work, tests, exit criteria) and what is wrong (wrong or missing dependencies for parallel building, tasks too big to finish in one go, acceptance items that cannot be checked, scope creep outside the block). Do not change any files.`,
  { schema: CRITIQUE, label: `critique ${BLOCK}` },
)

if (critique && (critique.missing.length || critique.problems.length)) {
  log(`plan critique: ${critique.missing.length} missing, ${critique.problems.length} problems; revising`)
  const revised = await agent(
    `${CONTEXT}

Revise this plan for block ${BLOCK}:
${JSON.stringify(plan, null, 2)}

A critic found:
Missing: ${JSON.stringify(critique.missing)}
Problems: ${JSON.stringify(critique.problems)}

Return the full revised plan. Keep task ids stable where the task is unchanged. Do not change any files.`,
    { schema: PLAN, label: `plan ${BLOCK} v2`, effort: 'high' },
  )
  if (revised) plan = revised
}

log(`${BLOCK}: ${plan.tasks.length} tasks, ${plan.exitCriteria.length} exit criteria, ${(plan.blockers || []).length} blockers, up to ${MAX_PARALLEL} in parallel`)

// ---------------------------------------------------------------- Build and integrate
const taskIds = new Set(plan.tasks.map(t => t.id))
const depsOf = t => (t.dependsOn || []).filter(d => taskIds.has(d))
const state = {}
plan.tasks.forEach(t => { state[t.id] = 'pending' })
const taskResults = {}

// The merge queue: one integration at a time, in completion order.
let queue = Promise.resolve()
function integrateSerially(task) {
  const run = queue.then(() => integrate(task))
  queue = run.catch(() => null)
  return run
}

async function integrate(task) {
  let lastFailures = []
  for (let round = 0; round <= MAX_INTEGRATION_ROUNDS; round++) {
    if (round > 0) {
      await agent(
        `${CONTEXT}

Task ${task.id} "${task.title}" of block ${BLOCK} failed integration in worktree ${wtOf(task.id)} (branch ${branchOf(task.id)}).
Failures: ${JSON.stringify(lastFailures)}

This usually means the task and work merged to main since it started interact. Find the cause and fix it in the worktree without undoing the other work, and without weakening any check (GUARDRAILS 7.3). Add a regression test when it is a bug. Commit with message "${BLOCK}-${task.id}: fix integration <what>". Never push.

${WORKTREE_RULES}`,
        { schema: TASK_RESULT, label: `fix integration ${task.id} #${round}`, phase: 'Integrate' },
      )
    }
    const r = await agent(
      `${CONTEXT}

Integrate task ${task.id} "${task.title}" of block ${BLOCK} into main. You are the only integrator running; nothing else merges while you work.

1. In the worktree ${wtOf(task.id)} on branch ${branchOf(task.id)}: rebase onto the current main of ${REPO} ("git rebase main"). Resolve conflicts so both sides' intent survives; if a conflict cannot be resolved faithfully, abort the rebase and report it as a failure.
2. In the worktree, reinstall if composer.lock or package-lock.json changed, then run every gate on the rebased result: "composer check". Also run "composer check:selftest" if the task or main since the task started changed a gate, the tool configuration or the check itself, and the containerized CI run (docker compose -f compose.ci.yaml run --rm ci, then down) if bin/ci, the CI files or the environment the gates need changed. Record the CI wall time.
3. Only if everything is green: fast-forward main ("git -C ${REPO} merge --ff-only ${branchOf(task.id)}"). If git refuses because the main checkout has local changes that the merge would overwrite, do not stash or discard them; report it as a failure.
4. After a successful merge, remove the worktree and delete the branch.
Never push. Do not edit PROGRESS.md.`,
      { schema: INTEGRATION, label: `integrate ${task.id}${round ? ' #' + round : ''}`, phase: 'Integrate', effort: 'medium' },
    )
    if (r && r.merged) return r
    lastFailures = r ? r.failures.concat(r.conflicts || []) : ['integrator died']
  }
  return { merged: false, failures: lastFailures, checksRun: [] }
}

async function buildTask(task) {
  const earlier = Object.values(taskResults).map(r => ({ id: r.id, status: r.status, summary: r.summary }))
  const result = await agent(
    `${CONTEXT}

Implement task ${task.id} "${task.title}" of block ${BLOCK}.
Goal: ${task.goal}
PRD references: ${JSON.stringify(task.prdRefs || [])}
Acceptance: ${JSON.stringify(task.acceptance)}
Tasks of this block finished so far: ${JSON.stringify(earlier)}

First create your worktree from the current main: "git -C ${REPO} worktree add ${wtOf(task.id)} -b ${branchOf(task.id)} main" (if it already exists from an earlier attempt, reuse it and rebase it onto main), then "composer install" and "npm ci" in it.

Write the code and its tests following GUARDRAILS.md. Run "composer check" and the acceptance items in the worktree and fix what fails. When green, commit on your branch with message "${BLOCK}-${task.id}: <what>". Do not merge into main; the merge queue does that. Never push. Never weaken a check (GUARDRAILS 7.3); if you think a check is wrong, leave it and report it in forHumanReview. If the task needs a decision reserved for Sylvester, stop and return status blocked with the blocker. Report PRD interpretations you made in interpretations.

${WORKTREE_RULES}`,
    { schema: TASK_RESULT, label: `build ${task.id}`, phase: 'Build' },
  )
  if (!result || result.status === 'blocked') {
    return { id: task.id, status: 'blocked', summary: result ? result.summary : 'agent died', blocker: result && result.blocker }
  }

  const verify = label => agent(
    `${CONTEXT}

Verify task ${task.id} "${task.title}" of block ${BLOCK} in its worktree ${wtOf(task.id)} (branch ${branchOf(task.id)}).
Goal: ${task.goal}
Acceptance: ${JSON.stringify(task.acceptance)}

${VERIFY_RULES}

${WORKTREE_RULES}`,
    { schema: VERIFY, label, phase: 'Build', effort: 'medium' },
  )

  let verdict = await verify(`verify ${task.id}`)
  let round = 0
  while (verdict && !verdict.pass && round < MAX_FIX_ROUNDS) {
    round++
    const fix = await agent(
      `${CONTEXT}

Task ${task.id} "${task.title}" of block ${BLOCK} failed verification in worktree ${wtOf(task.id)}.
Failures: ${JSON.stringify(verdict.failures)}
Acceptance: ${JSON.stringify(task.acceptance)}

Fix the cause, not the check (GUARDRAILS 7.3). Add a regression test when the failure is a bug. Run the checks, then commit on the branch with message "${BLOCK}-${task.id}: fix <what>". Never push.

${WORKTREE_RULES}`,
      { schema: TASK_RESULT, label: `fix ${task.id} #${round}`, phase: 'Build' },
    )
    if (fix) {
      result.interpretations = (result.interpretations || []).concat(fix.interpretations || [])
      result.forHumanReview = (result.forHumanReview || []).concat(fix.forHumanReview || [])
    }
    verdict = await verify(`verify ${task.id} #${round}`)
  }
  if (!verdict || !verdict.pass) {
    return {
      id: task.id, status: 'failed', summary: result.summary,
      failures: verdict ? verdict.failures : ['verifier died'],
      interpretations: result.interpretations || [], forHumanReview: result.forHumanReview || [],
    }
  }

  const merged = await integrateSerially(task)
  return {
    id: task.id,
    status: merged.merged ? 'done' : 'failed',
    summary: result.summary,
    failures: merged.merged ? [] : ['integration: ' + JSON.stringify(merged.failures)],
    integration: { mainHead: merged.mainHead, checksRun: merged.checksRun, wallTimeSeconds: merged.wallTimeSeconds },
    interpretations: result.interpretations || [],
    forHumanReview: result.forHumanReview || [],
  }
}

phase('Build')
const running = new Map()
while (true) {
  // A task whose dependency failed or was blocked cannot be built.
  let changed = true
  while (changed) {
    changed = false
    for (const t of plan.tasks) {
      if (state[t.id] !== 'pending') continue
      const bad = depsOf(t).find(d => state[d] === 'failed' || state[d] === 'blocked')
      if (bad) {
        state[t.id] = 'blocked'
        taskResults[t.id] = { id: t.id, status: 'blocked', summary: `depends on ${state[bad]} ${bad}` }
        log(`${t.id} skipped: depends on ${state[bad]} ${bad}`)
        changed = true
      }
    }
  }
  const ready = plan.tasks.filter(t => state[t.id] === 'pending' && depsOf(t).every(d => state[d] === 'done'))
  while (running.size < MAX_PARALLEL && ready.length) {
    const t = ready.shift()
    state[t.id] = 'running'
    running.set(t.id, buildTask(t).catch(e => ({ id: t.id, status: 'failed', summary: 'crashed', failures: [String(e)] })).then(r => {
      state[t.id] = r.status
      taskResults[t.id] = r
      running.delete(t.id)
      log(`${t.id}: ${r.status}`)
    }))
  }
  if (!running.size) break
  await Promise.race(running.values())
}
for (const t of plan.tasks) {
  if (state[t.id] === 'pending') {
    state[t.id] = 'blocked'
    taskResults[t.id] = { id: t.id, status: 'blocked', summary: 'dependency cycle or unknown dependency' }
    log(`${t.id} never became ready: dependency cycle`)
  }
}
const results = plan.tasks.map(t => taskResults[t.id])
const blockedTasks = results.filter(r => r.status === 'blocked').map(r => r.id)
const failedTasks = results.filter(r => r.status === 'failed')

// ---------------------------------------------------------------- Review
phase('Review')

const gate = await agent(
  `${CONTEXT}

Block-wide regression gate for ${BLOCK} on main in ${REPO}, after parallel tasks were merged one by one. Run "composer check", "composer check:selftest" and the containerized CI run (docker compose -f compose.ci.yaml run --rm ci, then down). Also check that no worktree of this block is left in ${WT_ROOT} for a task that was merged, and that "git worktree list" and the branches wip/${BLOCK}-* match the tasks that were not merged. Report failures precisely. Do not change any files.`,
  { schema: VERIFY, label: `regression gate ${BLOCK}`, phase: 'Review', effort: 'medium' },
)
if (gate && !gate.pass) {
  log(`regression gate failed: ${gate.failures.length} failures; fixing on main`)
  await agent(
    `${CONTEXT}

The block-wide regression gate for ${BLOCK} failed on main after the parallel tasks were merged:
${JSON.stringify(gate.failures)}

Find which merged tasks interact to cause it (git log, bisect if needed), fix the cause on main in ${REPO} with a regression test, never weakening a check (GUARDRAILS 7.3). Run "composer check" and whatever else failed, then commit with message "${BLOCK}-review: fix regression <what>". Never push.`,
    { schema: TASK_RESULT, label: `fix regression ${BLOCK}`, phase: 'Review' },
  )
}

const LENSES = [
  { key: 'prd', prompt: 'Does the code do what the PRD sections for this block say? Look for missing behaviour, wrong semantics and invariants from PRD 6.5 that are not enforced or not tested.' },
  { key: 'guardrails', prompt: 'Does the code follow GUARDRAILS.md: actions and plans, envelope, DTOs and value objects, no mixed or untyped arrays outside Boundary and Adapter, contracts with fakes that pass the shared contract tests, layers, readonly per category, codecs, the kernel knowing no content types, no placeholders?' },
  { key: 'correctness', prompt: 'Look for correctness bugs: concurrency and transactions (separate connections, expected versions, idempotency), RLS as the app role, outbox atomicity, time handling, edge cases. Prefer findings you can demonstrate with a failing test.' },
  { key: 'security', prompt: 'Look for security problems: SSRF (all outbound HTTP through laravel-ssrf), secrets in logs or events, authorization outside the pipelines, classification leaks, SQL injection, unsafe defaults.' },
  { key: 'integrity', prompt: 'Check the integrity of the checks themselves in this block\'s commits (git log and diffs): weakened analysis config, excluded paths, skipped or loosened tests, changed snapshots or expectations without reason, @phpstan-ignore outside Boundary and Adapter, generated files edited by hand. Pay special attention to integration fixes and conflict resolutions from the merge queue: did one task silently undo or weaken another task\'s work or test?' },
]

const seen = new Set()
const fixed = []
const keyOf = f => `${f.file}|${f.title}`.toLowerCase()

for (let reviewRound = 1; reviewRound <= MAX_REVIEW_ROUNDS; reviewRound++) {
  const found = (await parallel(LENSES.map(lens => () =>
    agent(
      `${CONTEXT}

Review the work of block ${BLOCK} on main (see the commits whose messages start with "${BLOCK}-") through one lens.
${lens.prompt}

Report only real problems with file and line and a concrete fix. No style nits, no praise. Do not change any files.`,
      { schema: FINDINGS, label: `review ${lens.key} r${reviewRound}`, phase: 'Review' },
    ).then(r => (r ? r.findings.map(f => Object.assign({}, f, { lens: lens.key })) : []))
  ))).filter(Boolean).flat()

  const fresh = found.filter(f => !seen.has(keyOf(f)))
  fresh.forEach(f => seen.add(keyOf(f)))
  if (!fresh.length) {
    log(`review round ${reviewRound}: nothing new`)
    break
  }

  const judged = await parallel(fresh.map((f, i) => () =>
    parallel([0, 1].map(k => () =>
      agent(
        `${CONTEXT}

A reviewer claims this problem in block ${BLOCK}:
${JSON.stringify(f, null, 2)}

Try to refute it. Read the code and, where possible, run or write a quick test to check it (do not commit anything, and leave the working tree as you found it). Return refuted=true only if the claim is wrong or does not matter for correctness, security or the PRD.${k === 1 ? ' Look especially for existing tests or code paths that already handle it.' : ''}`,
        { schema: VERDICT, label: `refute ${f.lens} ${i}.${k}`, phase: 'Review' },
      )
    )).then(votes => ({ f, real: votes.filter(Boolean).filter(v => !v.refuted).length >= 1 }))
  ))

  const confirmed = judged.filter(Boolean).filter(j => j.real).map(j => j.f)
  log(`review round ${reviewRound}: ${fresh.length} new, ${confirmed.length} confirmed`)

  for (const f of confirmed) {
    const fix = await agent(
      `${CONTEXT}

Fix this confirmed problem in block ${BLOCK} on main in ${REPO}:
${JSON.stringify(f, null, 2)}

Write a regression test that fails before the fix. Fix the cause, never the check. Run "composer check", then commit with message "${BLOCK}-review: <what>". Never push.`,
      { schema: TASK_RESULT, label: `fix review: ${f.title}`.slice(0, 60), phase: 'Review' },
    )
    fixed.push({ finding: f.title, severity: f.severity, status: fix ? fix.status : 'agent died' })
  }
}

// ---------------------------------------------------------------- Exit
phase('Exit')

const exit = await agent(
  `${CONTEXT}

Check the exit criteria of block ${BLOCK} on main:
${JSON.stringify(plan.exitCriteria, null, 2)}

Run each one for real and report pass with evidence (command and the relevant output). Also run the full checks ("composer check"). Do not change any files.`,
  { schema: EXIT, label: `exit ${BLOCK}`, effort: 'high' },
)

const blockStatus = exit && exit.allPass && !failedTasks.length && !blockedTasks.length
  ? 'done'
  : (blockedTasks.length && !failedTasks.length ? 'blocked' : 'incomplete')

const progress = await agent(
  `${CONTEXT}

Update PROGRESS.md for block ${BLOCK} in ${REPO} and commit it with message "${BLOCK}: progress". Never push.

Result:
- block status: ${blockStatus}
- tasks: ${JSON.stringify(results)}
- blocked tasks: ${JSON.stringify(blockedTasks)}
- plan blockers: ${JSON.stringify(plan.blockers || [])}
- regression gate: ${JSON.stringify(gate)}
- review fixes: ${JSON.stringify(fixed)}
- exit criteria: ${JSON.stringify(exit)}

Do this:
1. Set the block's row to ${blockStatus}. If it is done, set the next block that is todo to next.
2. Replace "Seneste kørsel" with a short summary: block, status, tasks done, failed and blocked, integration failures, review findings fixed, failing exit criteria.
3. Add new blockers under "Blokeret" with what they block, new interpretations under "Tolkninger", and new items under "Til review af Sylvester". Keep existing entries unless they are resolved.
4. Update "Kontroller kørt" with the latest result per check, including the CI wall time against the 15-minute budget.
5. List any worktree or wip/${BLOCK}-* branch left behind for a task that was not merged, so the next run can reuse or remove it.
6. Leave the STATUS line as it is; the main session decides it.`,
  { schema: PROGRESS_RESULT, label: 'update PROGRESS.md', effort: 'medium' },
)

return {
  block: BLOCK,
  status: blockStatus,
  nextBlock: progress ? progress.nextBlock : null,
  tasks: results.map(t => ({ id: t.id, status: t.status })),
  failedTasks: failedTasks.map(t => ({ id: t.id, failures: t.failures })),
  blockedTasks,
  blockers: plan.blockers || [],
  regressionGate: gate ? { pass: gate.pass, failures: gate.failures } : null,
  reviewFixes: fixed,
  exitCriteria: exit ? exit.criteria : null,
}
