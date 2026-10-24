// D-169 — tests de deploy/work-inventory/inventory.mjs sur des dépôts JETABLES et un faux serveur
// (ssh, docker et crontab simulés). Ne touche ni au dépôt courant, ni au réseau, ni à la production.
//   node --test deploy/work-inventory/tests/
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const INVENTORY = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'inventory.mjs');
const NOW = '2026-10-24T12:00:00Z';
let T, ORIGIN, WORK, REGISTRY, STUB, SNAP;

const sh = (cmd, args, cwd, env = {}) => execFileSync(cmd, args, { cwd, encoding: 'utf8', env: { ...process.env, ...env }, stdio: ['pipe', 'pipe', 'pipe'] });
const git = (args, cwd = WORK, env = {}) => sh('git', args, cwd, env);
const write = (f, s) => { fs.mkdirSync(path.dirname(f), { recursive: true }); fs.writeFileSync(f, s); };
const at = (d) => ({ GIT_AUTHOR_DATE: d, GIT_COMMITTER_DATE: d });
function commit(msg, files, date = '2026-10-20T10:00:00Z', cwd = WORK) {
  for (const [f, s] of Object.entries(files)) write(path.join(cwd, f), s);
  git(['add', '-A'], cwd); git(['commit', '-q', '-m', msg], cwd, at(date));
  return git(['rev-parse', 'HEAD'], cwd).trim();
}
function run(extra = [], env = {}) {
  const r = spawnSync(process.execPath, [INVENTORY, '--repo', WORK, '--registry', REGISTRY, '--now', NOW, '--offline', ...extra],
    { encoding: 'utf8', env: { ...process.env, INVENTORY_SNAPSHOT: SNAP, INVENTORY_SSH: `bash ${STUB}/fake-ssh.sh`, ...env } });
  return r;
}
const json = (extra = [], env = {}) => { const r = run(['--json', ...extra], env); assert.equal(r.status, 0, r.stderr); return JSON.parse(r.stdout); };
const sha256 = (s) => execFileSync('sha256sum', [], { input: s, encoding: 'utf8' }).split(' ')[0];

before(() => {
  T = fs.mkdtempSync(path.join(os.tmpdir(), 'work-inventory-'));
  ORIGIN = path.join(T, 'origin.git'); WORK = path.join(T, 'work'); STUB = path.join(T, 'stub'); SNAP = path.join(T, 'snap', 'last.json');
  REGISTRY = path.join(T, 'registry.json');
  git(['init', '-q', '--bare', '-b', 'main', ORIGIN], T);
  git(['init', '-q', '-b', 'main', WORK], T);
  git(['config', 'user.email', 't@t.test']); git(['config', 'user.name', 't']); git(['config', 'core.autocrlf', 'false']);
  git(['remote', 'add', 'origin', ORIGIN]);

  // Faux serveur : application, scripts installés, crontab.
  const SRV = path.join(T, 'server');
  write(path.join(SRV, 'app', 'releases', 'REL', 'backend', 'REVISION'), 'x');
  write(path.join(SRV, 'scripts', 'legacy.sh'), 'echo modifie sur le serveur\n');   // diffère de la référence (n° 9)
  write(path.join(SRV, 'scripts', 'versioned_ok.sh'), 'echo ok\n');
  write(path.join(SRV, 'scripts', 'inconnu.sh'), 'echo ?\n');                       // absent du manifeste
  write(path.join(SRV, 'crontab.txt'), '0 3 * * * /x/backup.sh\n5 5 * * * /x/ajout-manuel.sh\n');
  const manifest = {
    host: 'faux', app: { root: path.join(SRV, 'app').replace(/\\/g, '/'), phpContainer: 'php', containers: ['php', 'worker'] },
    crontabReference: 'deploy/production/server-reference/crontab.txt', watchDirs: [path.join(SRV, 'scripts').replace(/\\/g, '/')],
    scripts: [
      { path: `${path.join(SRV, 'scripts', 'legacy.sh').replace(/\\/g, '/')}`, sha256: sha256('echo reference\n') },
      { path: `${path.join(SRV, 'scripts', 'versioned_ok.sh').replace(/\\/g, '/')}`, source: 'deploy/server/versioned_ok.sh' },
      { path: `${path.join(SRV, 'scripts', 'versioned_missing.sh').replace(/\\/g, '/')}`, source: 'deploy/server/versioned_missing.sh' }, // n° 5
    ],
  };
  // Le faux « ssh » exécute la commande localement, avec docker et crontab simulés.
  write(path.join(STUB, 'docker'), `#!/usr/bin/env bash
case "$*" in
  *app:release:show*) printf '  Commit exécuté       %s\\n  Statut               OFFICIAL\\n  Version officielle   v2026.10.01-prod\\n' "$(cat '${SRV.replace(/\\/g, '/')}/app/current-sha')";;
  *migrations:status*) printf '| Migrations | Executed | 3 |\\n| | Executed Unavailable | 0 |\\n| | Available | 4 |\\n| | New | 1 |\\n';;
  inspect*) echo "running 0";;
esac
`);
  write(path.join(STUB, 'crontab'), `#!/usr/bin/env bash\ncat '${SRV.replace(/\\/g, '/')}/crontab.txt'\n`);
  // PATH POSIX : sous Git Bash, « C:/… » couperait PATH au deux-points.
  const posix = (p) => p.replace(/\\/g, '/').replace(/^([A-Za-z]):/, (_, d) => `/${d.toLowerCase()}`);
  write(path.join(STUB, 'fake-ssh.sh'), `#!/usr/bin/env bash\nexport PATH="${posix(STUB)}:$PATH"\nexec bash -c "\${@: -1}"\n`);
  fs.chmodSync(path.join(STUB, 'docker'), 0o755); fs.chmodSync(path.join(STUB, 'crontab'), 0o755);

  // Historique : A (tag de prod), puis B fusionné via une « PR » mais non déployé (n° 3, migration n° 4).
  const A = commit('init', {
    'backend/src/App.php': '<?php\n', 'backend/migrations/Version20260101000000.php': '<?php\n', 'docs/readme.md': 'doc\n',
    'deploy/server/versioned_ok.sh': 'echo ok\n', 'deploy/server/versioned_missing.sh': 'echo manquant\n',
    'deploy/production/server-reference/manifest.json': JSON.stringify(manifest, null, 1),
    'deploy/production/server-reference/crontab.txt': '0 3 * * * /x/backup.sh\n0 4 * * * /x/rotate.sh\n',
  }, '2026-10-01T10:00:00Z');
  git(['tag', '-a', 'v2026.10.01-prod', '-m', 'prod', A], WORK, at('2026-10-01T12:00:00Z'));
  write(path.join(SRV, 'app', 'current-sha'), A);
  fs.mkdirSync(path.join(SRV, 'app', 'releases', A, 'backend'), { recursive: true });
  write(path.join(SRV, 'app', 'releases', A, 'backend', 'REVISION'), A);
  // « current » : lien symbolique réel si possible, sinon fichier texte lu par readlink (Git Bash sans droits).
  try { fs.symlinkSync(`releases/${A}`, path.join(SRV, 'app', 'current'), 'dir'); } catch { /* voir ci-dessous */ }

  git(['checkout', '-q', '-b', 'feat/merged']);
  commit('feat: fonctionnalité fusionnée', { 'backend/src/Merged.php': '<?php\n', 'backend/migrations/Version20261010000000.php': '<?php\n' }, '2026-10-10T10:00:00Z');
  git(['checkout', '-q', 'main']);
  git(['merge', '--no-ff', '-q', '-m', 'Merge pull request #5 from x/feat/merged', 'feat/merged'], WORK, at('2026-10-11T10:00:00Z'));
  commit('docs: note', { 'docs/note.md': 'x\n' }, '2026-10-12T10:00:00Z');
  git(['push', '-q', 'origin', 'main', '--tags']);

  // n° 1 : branche poussée avec un commit non fusionné, absente du registre.
  git(['checkout', '-q', '-b', 'feat/non-suivie']);
  commit('feat: travail non suivi', { 'frontend/x.ts': 'x\n' }, '2026-10-22T10:00:00Z');
  git(['push', '-q', 'origin', 'feat/non-suivie']);
  // n° 2 / n° 8 : correctif utilisateur testé, PR ouverte depuis plusieurs jours.
  git(['checkout', '-q', 'main']); git(['checkout', '-q', '-b', 'fix/deconnexions']);
  commit('fix(auth): plus de déconnexions', { 'backend/src/App.php': '<?php // fix\n' }, '2026-10-15T10:00:00Z');
  git(['push', '-q', 'origin', 'fix/deconnexions']);
  const fixSha = git(['rev-parse', 'HEAD']).trim();
  git(['update-ref', 'refs/pull/7/head', fixSha], ORIGIN);
  // n° 7 : branche ancienne, jamais poussée, avec du travail inédit.
  git(['checkout', '-q', 'main']); git(['checkout', '-q', '-b', 'old/abandonnee']);
  commit('feat: ancienne idée', { 'backend/src/Old.php': '<?php\n' }, '2026-07-01T10:00:00Z');
  // Branche dont le contenu a été réintégré sous le même sujet (rebase) : « probablement intégrée ».
  git(['checkout', '-q', 'main']); git(['checkout', '-q', '-b', 'backup/avant-rebase']);
  commit('docs: note', { 'docs/note.md': 'version différente\n' }, '2026-10-12T09:00:00Z');
  git(['checkout', '-q', 'main']);
  git(['fetch', '-q', 'origin']);
  // n° 6 : worktree avec une modification non commitée (et une autre déjà identique à main).
  git(['worktree', 'add', '-q', path.join(T, 'wt-sale'), 'feat/non-suivie']);
  write(path.join(T, 'wt-sale', 'frontend', 'x.ts'), 'modifié localement\n');
  write(path.join(T, 'wt-sale', 'nouveau.txt'), 'non suivi\n');

  write(REGISTRY, JSON.stringify({
    settings: { remote: 'origin', mainRef: 'origin/main', staleDays: 3 },
    items: [
      { id: 'FIX-1', title: 'Déconnexions', description: 'd', kind: 'fix', priority: 'P1', userImpact: 'tous', status: 'teste', statusSince: '2026-10-15',
        refs: { branches: ['fix/deconnexions'], prs: [7] }, nextAction: 'déployer', lastReviewed: '2026-10-24' },
      { id: 'FEAT-5', title: 'Fusionnée', description: 'd', kind: 'feature', priority: 'P2', userImpact: 'u', status: 'deploye', statusSince: '2026-10-11',
        refs: { prs: [5], commits: ['feat/merged'] }, nextAction: 'vérifier', lastReviewed: '2026-10-24' },
      { id: 'DATA-1', title: 'Reprise', description: 'd', kind: 'data', priority: 'P2', userImpact: 'u', status: 'bloque', blockedReason: 'décision',
        refs: {}, pendingOps: [{ type: 'reprise-donnees', status: 'en-attente', description: 'Rattraper 9 salles', validation: 'utilisateur' },
          { type: 'reprise-donnees', status: 'fait', description: 'Ancienne reprise', confirmedAt: '2026-10-01', evidence: 'rapport de reprise' },
          { type: 'reprise-donnees', status: 'fait', description: 'Reprise déclarée sans preuve' }], nextAction: 'décider', lastReviewed: '2026-10-24' },
    ],
  }, null, 1));
});
after(() => { try { fs.rmSync(T, { recursive: true, force: true }); } catch { /* fichiers verrouillés sous Windows */ } });

test('1. branche avec un commit non fusionné, absente du registre → non suivie', () => {
  const r = json(['--no-snapshot']);
  const b = r.git.branches.find((x) => x.name === 'feat/non-suivie');
  assert.ok(b && b.commits.length === 1);
  assert.ok(r.analysis.untracked.some((u) => u.kind === 'branche' && u.ref === 'feat/non-suivie'));
});

test('2 et 8. correctif P1 testé, PR ouverte depuis plusieurs jours → en tête, « OUBLIÉ »', () => {
  const r = json(['--no-snapshot']);
  const d = r.analysis.derived.find((x) => x.id === 'FIX-1');
  assert.equal(d.urgent, true); assert.equal(d.forgotten, true); assert.equal(d.code, 'non fusionné');
  assert.equal(d.prs[0].state, 'non-fusionnee');
  const txt = run(['--no-snapshot']).stdout;
  const sec1 = txt.split('## 2.')[0];
  assert.match(sec1, /\*\*OUBLIÉ\*\* \*\*P1 FIX-1\*\*/);
});

test('3. PR fusionnée mais absente du tag de prod → « fusionné, absent du tag de prod »', () => {
  const r = json(['--no-snapshot']);
  assert.ok(r.git.mainNotInProd.some((c) => c.subject === 'feat: fonctionnalité fusionnée' && c.app));
  assert.ok(r.git.mainNotInProd.some((c) => c.subject === 'docs: note' && !c.app), 'docs hors archive applicative');
  const d = r.analysis.derived.find((x) => x.id === 'FEAT-5');
  assert.equal(d.code, 'fusionné, absent du tag de prod');
  assert.ok(d.warnings.some((w) => /pas dans le tag de prod/.test(w)), 'statut « Déployé » incohérent signalé');
});

test('4. migrations : fusionnée non déployée (Git) et non appliquée (serveur)', () => {
  const r = json(['--no-snapshot', '--server']);
  assert.deepEqual(r.git.migrations.notInProd, ['Version20261010000000']);
  assert.equal(r.server.status, 'vérifié', JSON.stringify(r.server));
  assert.equal(r.server.migrations.new, 1);
  assert.match(run(['--no-snapshot', '--server']).stdout, /MIGRATIONS NON APPLIQUÉES/);
});

test('5. script de sauvegarde versionné mais non installé', () => {
  const r = json(['--no-snapshot', '--server']);
  const s = r.server.scripts.find((x) => x.path.endsWith('versioned_missing.sh'));
  assert.equal(s.state, 'non installé');
  assert.equal(r.server.scripts.find((x) => x.path.endsWith('versioned_ok.sh')).state, 'conforme');
});

test('6. worktree avec des changements non commités → fichiers absents de Git listés, non suivi', () => {
  const r = json(['--no-snapshot']);
  const w = r.git.worktrees.find((x) => x.name === 'wt-sale');
  assert.deepEqual([...w.notPreserved].sort(), ['frontend/x.ts', 'nouveau.txt']);
  assert.ok(r.analysis.untracked.some((u) => u.kind === 'worktree' && u.ref === 'wt-sale'));
});

test('7. branche abandonnée jamais poussée avec du travail inédit → signalée', () => {
  const r = json(['--no-snapshot']);
  const b = r.git.branches.find((x) => x.name === 'old/abandonnee');
  assert.equal(b.hasRemote, false);
  assert.ok(r.analysis.untracked.some((u) => u.ref === 'old/abandonnee' && /JAMAIS POUSSÉE/.test(u.detail)));
  assert.match(run(['--no-snapshot']).stdout, /`old\/abandonnee` .*ancienne avec travail inédit.*jamais poussée/);
  const p = r.git.branches.find((x) => x.name === 'backup/avant-rebase');
  assert.equal(p.allProbable, true, 'même sujet dans main : probablement intégrée');
});

test('9. modification du serveur non reflétée dans les fichiers versionnés', () => {
  const r = json(['--no-snapshot', '--server']);
  assert.equal(r.server.scripts.find((x) => x.path.endsWith('legacy.sh')).state, 'modifié hors dépôt');
  assert.ok(r.server.unlisted.some((p) => p.endsWith('inconnu.sh')));
  assert.deepEqual(r.server.crontab.added, ['5 5 * * * /x/ajout-manuel.sh']);
  assert.deepEqual(r.server.crontab.removed, ['0 4 * * * /x/rotate.sh']);
  assert.ok(r.analysis.untracked.some((u) => u.kind === 'crontab' && u.detail.includes('ajout-manuel')));
});

test('10. opération de données de production en attente (seule la reprise confirmée avec preuve disparaît)', () => {
  const r = json(['--no-snapshot']);
  const d = r.analysis.derived.find((x) => x.id === 'DATA-1');
  assert.deepEqual(d.openOps.map((o) => o.description), ['Rattraper 9 salles', 'Reprise déclarée sans preuve']);
  const out = run(['--no-snapshot']).stdout;
  assert.match(out, /Migration ou reprise de données en attente\*\* — DATA-1 P2 : Rattraper 9 salles/);
  assert.match(out, /Reprise déclarée sans preuve.*SANS PREUVE d’exécution/);
  assert.doesNotMatch(out, /Ancienne reprise/);
});

test('release prévue : correctif P1 absent → en tête, et --fail-on urgent échoue', () => {
  const main = git(['rev-parse', 'origin/main']).trim();
  const r = run(['--no-snapshot', '--release', main, '--fail-on', 'urgent']);
  assert.equal(r.status, 1);
  assert.match(r.stdout, /ABSENT DE LA RELEASE PRÉVUE/);
  assert.match(r.stdout, /Risques de conflit avec la release prévue/);
  assert.equal(run(['--no-snapshot', '--release', main]).status, 0, 'signalement seul par défaut');
});

test('registre invalide détecté (--check-registry)', () => {
  const bad = path.join(T, 'bad.json');
  write(bad, JSON.stringify({ items: [{ id: 'X', status: 'termine', priority: 'P9', kind: 'fix' }, { id: 'X' }] }));
  const r = spawnSync(process.execPath, [INVENTORY, '--repo', WORK, '--registry', bad, '--check-registry'], { encoding: 'utf8' });
  assert.equal(r.status, 1);
  assert.match(r.stderr, /statut inconnu « termine »/); assert.match(r.stderr, /id en double/); assert.match(r.stderr, /priorité inconnue/);
});

test('serveur injoignable → « Non vérifié », jamais conforme par défaut', () => {
  const r = json(['--no-snapshot', '--server'], { INVENTORY_SSH: 'bash -c exit_1_inexistant' });
  assert.equal(r.server.status, 'Non vérifié');
});

test('instantané : signale ce qui est nouveau depuis le dernier audit', () => {
  fs.rmSync(SNAP, { force: true });
  json([]);                                     // premier audit : instantané écrit
  git(['branch', 'feat/nouvelle', 'old/abandonnee']);
  git(['checkout', '-q', 'feat/nouvelle']); commit('feat: toute nouvelle', { 'backend/src/New.php': '<?php\n' }, '2026-10-24T09:00:00Z'); git(['checkout', '-q', 'main']);
  const r = json([]);
  assert.ok(r.news.previous);
  assert.ok(r.news.added.some((k) => k.startsWith('commit:feat/nouvelle:')), JSON.stringify(r.news.added));
  assert.ok(!r.news.added.some((k) => k.startsWith('commit:feat/non-suivie:')), 'déjà connu au dernier audit');
});
