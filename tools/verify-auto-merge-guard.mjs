#!/usr/bin/env node
/**
 * Proves the auto-merge workflow by executing the script that is actually
 * committed.
 *
 * The whole `script:` block is lifted out of
 * .github/workflows/auto-merge-release-please.yml and run against a fake
 * `github`, `context` and `core`. Nothing here restates the workflow's logic: a
 * copy could drift from the file and still pass, which would make this worse
 * than no test at all.
 */
import { readFileSync } from 'node:fs';

const WORKFLOW = '../.github/workflows/auto-merge-release-please.yml';
const workflow = readFileSync(new URL(WORKFLOW, import.meta.url), 'utf8');

/** The `script: |` block, dedented. */
function extractScript(source) {
  const lines = source.split('\n');
  const start = lines.findIndex((l) => /^\s*script:\s*\|\s*$/.test(l));

  if (start < 0) throw new Error('No `script: |` block found in the workflow.');

  const indent = lines[start + 1].match(/^ */)[0].length;
  const body = [];

  for (const line of lines.slice(start + 1)) {
    if (line.trim() !== '' && line.match(/^ */)[0].length < indent) break;
    body.push(line.slice(indent));
  }

  return body.join('\n');
}

const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const script = new AsyncFunction('github', 'context', 'core', extractScript(workflow));

const genuine = () => ({
  number: 99,
  draft: false,
  node_id: 'PR_node',
  user: { login: 'akaienso-release-please[bot]', type: 'Bot' },
  base: { ref: 'main' },
  head: { ref: 'release-please--branches--main', repo: { full_name: 'akaienso/post-domain' } },
  labels: [{ name: 'autorelease: pending' }],
});

const queuedAs = (mergeMethod) => ({ enabledAt: '2026-08-29T00:00:00Z', mergeMethod });

/** A graphql double that records what was asked and answers from a script. */
function graphqlDouble({ onQuery, onMutation }) {
  const calls = { query: 0, mutation: 0, methods: [] };

  const graphql = async (document, variables) => {
    if (/^\s*mutation/m.test(document)) {
      calls.mutation += 1;
      calls.methods.push(variables.mergeMethod);
      return onMutation(variables);
    }

    calls.query += 1;
    return onQuery(variables);
  };

  return { graphql, calls };
}

/** Runs the committed script once and reports what happened. */
async function run(pr, doubles) {
  const { graphql, calls } = graphqlDouble(doubles);
  const log = [];
  let failure = null;

  const core = {
    info: (m) => log.push(m),
    warning: (m) => log.push(`WARNING: ${m}`),
    setFailed: (m) => { failure = m; },
  };

  let thrown = null;

  try {
    await script({ graphql }, { payload: { pull_request: pr } }, core);
  } catch (error) {
    thrown = error;
  }

  const warned = log.some((l) => l.startsWith('WARNING:'));

  return { calls, failed: failure !== null || thrown !== null, warned, thrown, failure, log };
}

const ok = (answer) => ({ node: { number: 99, autoMergeRequest: answer } });
const enabled = (answer) => ({
  enablePullRequestAutoMerge: { pullRequest: { number: 99, autoMergeRequest: answer } },
});

const NEVER = () => { throw new Error('should not have been called'); };

const cases = [
  // --- the happy paths -----------------------------------------------------
  {
    name: 'genuine release PR, nothing queued yet: queues SQUASH',
    pr: genuine(),
    doubles: { onQuery: async () => ok(null), onMutation: async () => enabled(queuedAs('SQUASH')) },
    expect: (r) => !r.failed && r.calls.mutation === 1 && r.calls.methods[0] === 'SQUASH',
  },
  {
    name: 'genuine release PR already queued as SQUASH: idempotent, no mutation',
    pr: genuine(),
    doubles: { onQuery: async () => ok(queuedAs('SQUASH')), onMutation: NEVER },
    expect: (r) => !r.failed && r.calls.mutation === 0,
  },

  // --- reliability: every failure must fail the run ------------------------
  {
    name: 'already queued as MERGE rather than SQUASH: fails',
    pr: genuine(),
    doubles: { onQuery: async () => ok(queuedAs('MERGE')), onMutation: NEVER },
    expect: (r) => r.failed && r.calls.mutation === 0,
  },
  {
    name: 'the state query fails: fails, and never blind-fires the mutation',
    pr: genuine(),
    doubles: { onQuery: async () => { throw new Error('502 bad gateway'); }, onMutation: NEVER },
    expect: (r) => r.failed && r.calls.mutation === 0,
  },
  {
    name: 'the mutation fails: fails',
    pr: genuine(),
    doubles: {
      onQuery: async () => ok(null),
      onMutation: async () => { throw new Error('Resource not accessible by integration'); },
    },
    expect: (r) => r.failed,
  },
  {
    name: 'the mutation returns null: fails',
    pr: genuine(),
    doubles: { onQuery: async () => ok(null), onMutation: async () => null },
    expect: (r) => r.failed,
  },
  {
    name: 'the mutation returns a malformed payload: fails',
    pr: genuine(),
    doubles: { onQuery: async () => ok(null), onMutation: async () => enabled(null) },
    expect: (r) => r.failed,
  },
  {
    name: 'the mutation queues the wrong merge method: fails',
    pr: genuine(),
    doubles: { onQuery: async () => ok(null), onMutation: async () => enabled(queuedAs('REBASE')) },
    expect: (r) => r.failed,
  },

  // --- authorization: none of these may touch the API at all ---------------
  ...[
    ['an ordinary human feature PR', {
      user: { login: 'akaienso', type: 'User' },
      head: { ref: 'feat/something', repo: { full_name: 'akaienso/post-domain' } },
      labels: [],
    }],
    ['a fork branch with the release branch name', {
      head: { ref: 'release-please--branches--main', repo: { full_name: 'attacker/post-domain' } },
    }],
    ['an account whose name merely contains the bot name', {
      user: { login: 'not-akaienso-release-please[bot]', type: 'Bot' },
    }],
    ['a human impersonating the bot login', {
      user: { login: 'akaienso-release-please[bot]', type: 'User' },
    }],
    ['the real bot, but no autorelease label', { labels: [{ name: 'chore' }] }],
    ['the real bot, but already tagged', { labels: [{ name: 'autorelease: tagged' }] }],
    ['the real bot, but a draft', { draft: true }],
    ['the real bot, but targeting another base branch', { base: { ref: 'develop' } }],
    ['Dependabot', {
      user: { login: 'dependabot[bot]', type: 'Bot' },
      head: { ref: 'dependabot/composer/phpunit', repo: { full_name: 'akaienso/post-domain' } },
      labels: [{ name: 'dependencies' }],
    }],
    ['a chore PR carrying the label by hand', {
      user: { login: 'akaienso', type: 'User' },
      head: { ref: 'chore/tidy', repo: { full_name: 'akaienso/post-domain' } },
    }],
  ].map(([name, overrides]) => ({
    name: `${name}: no API call, no merge`,
    pr: { ...genuine(), ...overrides },
    doubles: { onQuery: NEVER, onMutation: NEVER },
    expect: (r) => !r.failed && r.calls.query === 0 && r.calls.mutation === 0,
  })),
];

let failures = 0;

for (const { name, pr, doubles, expect } of cases) {
  const result = await run(pr, doubles);
  const passed = expect(result);

  // A swallowed failure is the defect this file exists to prevent.
  const swallowed = result.warned && !result.failed;

  if (!passed || swallowed) failures += 1;

  const detail = swallowed
    ? '  (a failure was reduced to a warning)'
    : result.failed
      ? `  (failed: ${(result.failure || result.thrown.message).slice(0, 72)})`
      : `  (query ${result.calls.query}, mutation ${result.calls.mutation})`;

  console.log(`${passed && !swallowed ? 'OK  ' : 'FAIL'}  ${name}${detail}`);
}

console.log(`\n${cases.length} cases, ${failures} unexpected.`);
process.exit(failures === 0 ? 0 : 1);
