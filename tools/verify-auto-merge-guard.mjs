#!/usr/bin/env node
/**
 * Proves the auto-merge guard by executing the code that is actually committed.
 *
 * The guard is extracted from .github/workflows/auto-merge-release-please.yml
 * and evaluated against pull-request payloads. Nothing here restates the
 * conditions: a copy could drift from the workflow and still pass, which would
 * make this worse than no test at all.
 */
import { readFileSync } from 'node:fs';

const workflow = readFileSync(
  new URL('../.github/workflows/auto-merge-release-please.yml', import.meta.url),
  'utf8',
);

// Lift the guard verbatim: from the EXPECTED table through the `failed` list.
const start = workflow.indexOf('const EXPECTED = {');
const end = workflow.indexOf('.map(([name]) => name);');

if (start < 0 || end < 0) {
  console.error('Could not locate the guard in the workflow. Has it been renamed?');
  process.exit(2);
}

const guardSource = workflow
  .slice(start, end + '.map(([name]) => name);'.length)
  .split('\n')
  .map((line) => line.replace(/^ {12}/, ''))
  .join('\n');

const evaluate = new Function(
  'pr',
  `${guardSource}\nreturn { failed, guards };`,
);

const genuine = () => ({
  number: 99,
  draft: false,
  node_id: 'PR_node',
  user: { login: 'akaienso-release-please[bot]', type: 'Bot' },
  base: { ref: 'main' },
  head: { ref: 'release-please--branches--main', repo: { full_name: 'akaienso/post-domain' } },
  labels: [{ name: 'autorelease: pending' }],
});

const cases = [
  ['the genuine Release Please PR', genuine(), true],

  ['an ordinary human feature PR', {
    ...genuine(),
    user: { login: 'akaienso', type: 'User' },
    head: { ref: 'feat/something', repo: { full_name: 'akaienso/post-domain' } },
    labels: [],
  }, false],

  ['a fork branch with the release branch name', {
    ...genuine(),
    head: { ref: 'release-please--branches--main', repo: { full_name: 'attacker/post-domain' } },
  }, false],

  ['an impostor account whose name merely contains the bot name', {
    ...genuine(),
    user: { login: 'not-akaienso-release-please[bot]', type: 'Bot' },
  }, false],

  ['the real bot, but no autorelease label', {
    ...genuine(),
    labels: [{ name: 'chore' }],
  }, false],

  ['the real bot, but already tagged rather than pending', {
    ...genuine(),
    labels: [{ name: 'autorelease: tagged' }],
  }, false],

  ['the real bot, but a draft', { ...genuine(), draft: true }, false],

  ['the real bot, but targeting another base branch', {
    ...genuine(),
    base: { ref: 'develop' },
  }, false],

  ['Dependabot', {
    ...genuine(),
    user: { login: 'dependabot[bot]', type: 'Bot' },
    head: { ref: 'dependabot/composer/phpunit', repo: { full_name: 'akaienso/post-domain' } },
    labels: [{ name: 'dependencies' }],
  }, false],

  ['a chore PR carrying the label by hand', {
    ...genuine(),
    user: { login: 'akaienso', type: 'User' },
    head: { ref: 'chore/tidy', repo: { full_name: 'akaienso/post-domain' } },
  }, false],
];

let failures = 0;

for (const [name, pr, shouldPass] of cases) {
  const { failed } = evaluate(pr);
  const passed = failed.length === 0;
  const ok = passed === shouldPass;

  if (!ok) failures += 1;

  const verdict = passed ? 'AUTO-MERGES' : 'requires a human';
  const detail = passed ? '' : `  (unmet: ${failed.join(', ')})`;
  console.log(`${ok ? 'OK  ' : 'FAIL'}  ${name}: ${verdict}${detail}`);
}

console.log(`\n${cases.length} cases, ${failures} unexpected.`);
process.exit(failures === 0 ? 0 : 1);
