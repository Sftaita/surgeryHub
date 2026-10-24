#!/usr/bin/env node
// SurgicalHub — inventaire des travaux non intégrés, non déployés ou non installés (D-169).
//
// Calcule à partir de Git (et, sur option, du serveur en LECTURE SEULE) tout ce qui reste à
// fusionner, déployer, installer ou décider, et le croise avec le registre des décisions humaines
// (docs/work-registry.json). Tout travail détecté sans entrée de registre ressort « NON SUIVI ».
//
//   node deploy/work-inventory/inventory.mjs                 rapport complet (Git local + PR GitHub)
//   node deploy/work-inventory/inventory.mjs --fetch         … après git fetch (branches, tags, refs/pull/*)
//   node deploy/work-inventory/inventory.mjs --server        … + serveur (ssh, lecture seule)
//   node deploy/work-inventory/inventory.mjs --release <sha> contrôle avant déploiement de <sha>
//   node deploy/work-inventory/inventory.mjs --check-registry  validation du registre seulement
//
// Options : --json, --summary, --offline (pas d'API GitHub), --no-snapshot, --deep (commits
// inaccessibles), --fail-on untracked,urgent,registry,server (code de sortie 1 ; défaut : jamais),
// --repo <dir>, --registry <fichier>, --main <ref>, --now <date ISO> (tests).
// Environnement : INVENTORY_SSH (défaut « ssh -o ConnectTimeout=20 -o BatchMode=yes surgicalhub-prod »),
// GITHUB_TOKEN (facultatif, lecture des PR), INVENTORY_SNAPSHOT (fichier de l'audit précédent).
//
// Le script n'écrit jamais rien sur le serveur ni dans le dépôt suivi. Il écrit seulement, sauf
// --no-snapshot, l'instantané de l'audit dans <git-common-dir>/work-inventory/last.json (hors suivi),
// et, avec --fetch, des refs de suivi locales (refs/remotes/origin/pr/*).

import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

// ── Options ────────────────────────────────────────────────────────────────────
const argv = process.argv.slice(2);
const opt = { failOn: [], fetch: false, server: false, json: false, summary: false, offline: false, snapshot: true, deep: false, checkOnly: false };
for (let i = 0; i < argv.length; i++) {
  const a = argv[i];
  const val = () => { if (i + 1 >= argv.length) usage(`valeur attendue après ${a}`); return argv[++i]; };
  switch (a) {
    case '--fetch': opt.fetch = true; break;
    case '--server': opt.server = true; break;
    case '--json': opt.json = true; break;
    case '--summary': opt.summary = true; break;
    case '--offline': opt.offline = true; break;
    case '--no-snapshot': opt.snapshot = false; break;
    case '--deep': opt.deep = true; break;
    case '--check-registry': opt.checkOnly = true; break;
    case '--release': opt.release = val(); break;
    case '--repo': opt.repo = val(); break;
    case '--registry': opt.registry = val(); break;
    case '--main': opt.main = val(); break;
    case '--now': opt.now = val(); break;
    case '--fail-on': opt.failOn = val().split(',').map((s) => s.trim()).filter(Boolean); break;
    case '-h': case '--help': usage(); break;
    default: usage(`option inconnue : ${a}`);
  }
}
function usage(msg) {
  if (msg) console.error(`ERREUR : ${msg}`);
  const head = fs.readFileSync(new URL(import.meta.url), 'utf8').split('\n').slice(1, 22).map((l) => l.replace(/^\/\/ ?/, ''));
  console.error(head.join('\n'));
  process.exit(msg ? 2 : 0);
}

const NOW = opt.now ? new Date(opt.now) : new Date();
const DAY = 86400000;
const daysSince = (d) => (d ? Math.floor((NOW - new Date(d)) / DAY) : null);

// ── Git ────────────────────────────────────────────────────────────────────────
const repo = opt.repo ? path.resolve(opt.repo) : sh('git', ['rev-parse', '--show-toplevel'], process.cwd()).trim();
function sh(cmd, args, cwd = repo, input) {
  return execFileSync(cmd, args, { cwd, encoding: 'utf8', input, maxBuffer: 256 * 1024 * 1024, stdio: ['pipe', 'pipe', 'pipe'] });
}
function git(args, cwd = repo) { return sh('git', args, cwd); }
function gitOk(args, cwd = repo) { return spawnSync('git', args, { cwd, encoding: 'utf8' }).status === 0; }
function gitTry(args, cwd = repo) { const r = spawnSync('git', args, { cwd, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 }); return r.status === 0 ? r.stdout : null; }
const lines = (s) => (s || '').split('\n').map((l) => l.replace(/\r$/, '')).filter((l) => l.length);
const short = (sha) => (sha || '').slice(0, 7);

// ── Registre ───────────────────────────────────────────────────────────────────
const STATUSES = {
  'a-analyser': 'À analyser', 'en-developpement': 'En développement', developpe: 'Développé', teste: 'Testé',
  'pr-ouverte': 'PR ouverte', fusionne: 'Fusionné', deploye: 'Déployé', 'valide-production': 'Validé en production',
  bloque: 'Bloqué', abandonne: 'Abandonné ou remplacé',
};
const OP_TYPES = {
  'installation-serveur': 'Installation serveur en attente', 'reprise-donnees': 'Migration ou reprise de données en attente',
  'activation-configuration': 'Activation de configuration en attente', validation: 'Opération soumise à validation',
};
const OP_STATUSES = ['en-attente', 'en-cours', 'fait', 'abandonne'];
const PRIORITIES = ['P0', 'P1', 'P2', 'P3'];
const KINDS = ['fix', 'feature', 'ops', 'data', 'debt', 'docs', 'test'];
const NOT_IN_PROD_YET = ['developpe', 'teste', 'pr-ouverte', 'fusionne'];
const registryPath = opt.registry ? path.resolve(opt.registry) : path.join(repo, 'docs', 'work-registry.json');

function loadRegistry() {
  if (!fs.existsSync(registryPath)) return { registry: { items: [], settings: {} }, errors: [`registre absent : ${registryPath}`] };
  let registry;
  try { registry = JSON.parse(fs.readFileSync(registryPath, 'utf8')); } catch (e) { return { registry: { items: [], settings: {} }, errors: [`registre illisible : ${e.message}`] }; }
  return { registry, errors: validateRegistry(registry) };
}
function validateRegistry(r) {
  const errs = [];
  const isDate = (d) => typeof d === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(d) && !Number.isNaN(Date.parse(d));
  if (!Array.isArray(r.items)) return ['« items » doit être une liste'];
  const ids = new Set();
  r.items.forEach((it, i) => {
    const w = (m) => errs.push(`${it.id || `#${i}`} : ${m}`);
    if (!it.id || typeof it.id !== 'string') w('id manquant');
    else if (ids.has(it.id)) w('id en double'); else ids.add(it.id);
    for (const f of ['title', 'description', 'userImpact', 'nextAction']) if (!it[f] || typeof it[f] !== 'string') w(`champ « ${f} » manquant`);
    if (!STATUSES[it.status]) w(`statut inconnu « ${it.status} » (${Object.keys(STATUSES).join(', ')})`);
    if (!PRIORITIES.includes(it.priority)) w(`priorité inconnue « ${it.priority} »`);
    if (!KINDS.includes(it.kind)) w(`nature inconnue « ${it.kind} » (${KINDS.join(', ')})`);
    if (!isDate(it.lastReviewed)) w('« lastReviewed » attendu au format AAAA-MM-JJ');
    if (it.statusSince !== undefined && !isDate(it.statusSince)) w('« statusSince » attendu au format AAAA-MM-JJ');
    if (it.status === 'bloque' && !it.blockedReason) w('statut « bloque » sans « blockedReason »');
    if (it.status === 'abandonne' && !it.abandonDecision) w('statut « abandonne » sans « abandonDecision » (décision explicite)');
    if (it.status === 'valide-production' && !it.prodEvidence) w('statut « valide-production » sans « prodEvidence »');
    const refs = it.refs || {};
    for (const k of ['branches', 'prs', 'commits', 'worktrees', 'preservedIn', 'migrations', 'serverPaths']) {
      if (refs[k] !== undefined && !Array.isArray(refs[k])) w(`refs.${k} doit être une liste`);
    }
    (it.pendingOps || []).forEach((op, j) => {
      if (!OP_TYPES[op.type]) w(`pendingOps[${j}] : type inconnu « ${op.type} » (${Object.keys(OP_TYPES).join(', ')})`);
      if (!OP_STATUSES.includes(op.status)) w(`pendingOps[${j}] : statut inconnu « ${op.status} »`);
      if (!op.description) w(`pendingOps[${j}] : description manquante`);
    });
  });
  return errs;
}

// ── Collecte Git ───────────────────────────────────────────────────────────────
function collectGit(registry) {
  const settings = registry.settings || {};
  const remote = settings.remote || 'origin';
  if (opt.fetch) {
    gitTry(['fetch', '--prune', '--tags', '--quiet', remote]);
    gitTry(['fetch', '--quiet', remote, `+refs/pull/*/head:refs/remotes/${remote}/pr/*`]);
  }
  const mainRef = opt.main || settings.mainRef || `${remote}/main`;
  const mainSha = git(['rev-parse', mainRef]).trim();

  // Dernier tag de production (par date de création, comme docs/deployment-versioning.md §1).
  const tags = lines(git(['for-each-ref', '--sort=-creatordate', '--format=%(refname:short)|%(creatordate:iso-strict)', 'refs/tags']))
    .map((l) => l.split('|')).filter(([t]) => /^v\d{4}\.\d{2}\.\d{2}-prod(-\d+)?$/.test(t));
  const prodTag = tags.length ? { name: tags[0][0], date: tags[0][1], sha: git(['rev-parse', `${tags[0][0]}^{commit}`]).trim() } : null;

  const perimeter = settings.deployPerimeter || ['backend/', 'frontend/', 'docker/', 'deploy/production/docker-compose.yml', '.dockerignore'];
  const inPerimeter = (f) => perimeter.some((p) => (p.endsWith('/') ? f.startsWith(p) : f === p));

  // Code fusionné non déployé.
  const mainNotInProd = prodTag ? lines(git(['log', '--no-merges', '--format=%H|%cI|%s', `${prodTag.sha}..${mainSha}`])).map((l) => {
    const [sha, date, subject] = splitN(l, '|', 3);
    const files = lines(git(['diff-tree', '--no-commit-id', '--name-only', '-r', sha]));
    return { sha, date, subject, files, app: files.some(inPerimeter) };
  }) : [];
  const mergedPrsNotInProd = prodTag ? lines(git(['log', '--merges', '--format=%H|%s', `${prodTag.sha}..${mainSha}`]))
    .map((l) => splitN(l, '|', 2)).map(([sha, s]) => ({ sha, pr: Number((s.match(/pull request #(\d+)/) || [])[1]) || null, subject: s })) : [];

  // Sujets présents dans main (détection « probablement intégré autrement »).
  const mainSubjects = new Map();
  for (const l of lines(git(['log', '--format=%H|%s', mainSha]))) { const [sha, s] = splitN(l, '|', 2); if (!mainSubjects.has(s)) mainSubjects.set(s, sha); }

  // Branches locales et distantes.
  const remoteTips = new Set(lines(git(['for-each-ref', '--format=%(objectname)', `refs/remotes/${remote}`])));
  const refsRaw = lines(git(['for-each-ref', '--format=%(refname)|%(objectname)|%(committerdate:iso-strict)|%(upstream:short)', 'refs/heads', `refs/remotes/${remote}`]));
  const branches = [];
  for (const l of refsRaw) {
    const [ref, sha, date, upstream] = l.split('|');
    if (ref.endsWith('/HEAD') || ref.startsWith(`refs/remotes/${remote}/pr/`)) continue;
    const local = ref.startsWith('refs/heads/');
    const name = local ? ref.slice(11) : ref.slice(`refs/remotes/${remote}/`.length);
    if (sha === mainSha || name === 'main') continue;
    if (gitOk(['merge-base', '--is-ancestor', sha, mainSha])) continue;
    const unique = lines(git(['cherry', mainSha, sha])).filter((c) => c.startsWith('+ ')).map((c) => c.slice(2));
    if (!unique.length) continue; // intégré par cherry-pick (même patch)
    const commits = unique.map((u) => {
      const [s, d, subject] = splitN(git(['log', '-1', '--format=%H|%cI|%s', u]).trim(), '|', 3);
      return { sha: s, date: d, subject, probableIn: mainSubjects.get(subject) || null };
    });
    const pushed = local ? [...remoteTips].some((t) => t === sha || gitOk(['merge-base', '--is-ancestor', sha, t])) : true;
    const migrations = lines(git(['diff', '--name-only', '--diff-filter=A', `${mainSha}...${sha}`]))
      .filter((f) => /^backend\/migrations\/Version\d+\.php$/.test(f)).map((f) => path.basename(f, '.php'));
    const behind = Number(git(['rev-list', '--count', `${sha}..${mainSha}`]).trim());
    const conflicts = mergeConflicts(mainSha, sha);
    branches.push({ name, ref, local, sha, date, upstream: upstream || null, commits, pushed, migrations, behind, conflicts,
      allProbable: commits.every((c) => c.probableIn) });
  }
  // Fusionne local/distant d'une même branche (un seul élément, deux états).
  const byName = new Map();
  for (const b of branches) {
    const cur = byName.get(b.name) || { name: b.name, local: null, remote: null };
    cur[b.local ? 'local' : 'remote'] = b; byName.set(b.name, cur);
  }
  const branchList = [...byName.values()].map((e) => {
    const main = e.local || e.remote;
    const localAhead = e.local && e.remote && e.local.sha !== e.remote.sha && !gitOk(['merge-base', '--is-ancestor', e.local.sha, e.remote.sha]);
    return { ...main, hasLocal: !!e.local, hasRemote: !!e.remote || (e.local && e.local.pushed), localAhead: !!localAhead,
      remoteOnlySha: e.remote ? e.remote.sha : null };
  });

  const worktrees = collectWorktrees(mainSha, registry, settings);
  const stashes = lines(gitTry(['stash', 'list', '--format=%gd|%cI|%s']) || '').map((l) => { const [ref, date, s] = splitN(l, '|', 3); return { ref, date, subject: s }; });
  let unreachable = null;
  if (opt.deep) {
    const out = gitTry(['fsck', '--unreachable', '--no-reflogs', '--no-progress']) || '';
    unreachable = lines(out).filter((l) => l.startsWith('unreachable commit ')).map((l) => l.split(' ')[2]).map((sha) => {
      const [s, d, subject] = splitN(git(['log', '-1', '--format=%H|%cI|%s', sha]).trim(), '|', 3); return { sha: s, date: d, subject };
    }).sort((a, b) => (a.date < b.date ? 1 : -1));
  }

  // Migrations : fusionnées non déployées (présentes dans main, absentes du tag de prod).
  const migs = (ref) => new Set(lines(gitTry(['ls-tree', '--name-only', `${ref}:backend/migrations`]) || '').filter((f) => /^Version\d+\.php$/.test(f)).map((f) => f.slice(0, -4)));
  const mainMigs = migs(mainSha);
  const prodMigs = prodTag ? migs(prodTag.sha) : new Set();
  const migrationsNotInProd = [...mainMigs].filter((m) => !prodMigs.has(m)).sort();

  let release = null;
  if (opt.release) release = collectRelease(opt.release, prodTag, branchList, mainSha);

  return { remote, mainRef, mainSha, prodTag, mainNotInProd, mergedPrsNotInProd, branches: branchList, worktrees, stashes, unreachable,
    migrations: { main: mainMigs.size, prod: prodMigs.size, notInProd: migrationsNotInProd }, release };
}

function splitN(s, sep, n) { const parts = s.split(sep); return [...parts.slice(0, n - 1), parts.slice(n - 1).join(sep)]; }

function mergeConflicts(a, b) {
  const r = spawnSync('git', ['merge-tree', '--write-tree', '--name-only', '--no-messages', a, b], { cwd: repo, encoding: 'utf8' });
  if (r.status === 0) return [];
  if (r.status === 1) return lines(r.stdout).slice(1); // 1re ligne : arbre ; ensuite : fichiers en conflit
  return null; // merge-tree indisponible (Git < 2.38) : non vérifié
}

function collectWorktrees(mainSha, registry, settings) {
  const ignored = settings.ignoredDirtyPaths || [];
  const out = [];
  let cur = null;
  for (const l of lines(git(['worktree', 'list', '--porcelain'])).concat([''])) {
    if (l.startsWith('worktree ')) { if (cur) out.push(cur); cur = { path: l.slice(9), branch: null, detached: false }; }
    else if (l.startsWith('HEAD ')) cur.head = l.slice(5);
    else if (l.startsWith('branch ')) cur.branch = l.slice(7).replace('refs/heads/', '');
    else if (l === 'detached') cur.detached = true;
    else if (l === 'prunable' || l.startsWith('prunable')) cur.prunable = true;
  }
  if (cur) out.push(cur);
  for (const w of out) {
    w.name = path.basename(w.path);
    if (!fs.existsSync(w.path)) { w.missing = true; continue; }
    w.inMain = gitOk(['merge-base', '--is-ancestor', w.head, mainSha]);
    if (w.detached && !w.inMain) {
      const containing = lines(gitTry(['for-each-ref', '--contains', w.head, '--format=%(refname:short)', 'refs/heads', 'refs/remotes']) || '');
      w.orphanHead = containing.length === 0;
    }
    const st = gitTry(['status', '--porcelain=v1', '--untracked-files=all', '-z'], w.path);
    if (st === null) { w.statusError = true; continue; }
    const entries = st.split('\0').filter(Boolean);
    const files = [];
    for (let i = 0; i < entries.length; i++) {
      const e = entries[i]; const code = e.slice(0, 2); const f = e.slice(3);
      if (code[0] === 'R' || code[0] === 'C') i++; // nom d'origine
      if (ignored.includes(f)) continue;
      files.push({ code, file: f });
    }
    w.dirty = files;
    if (files.length) {
      const item = (registry.items || []).find((it) => (it.refs?.worktrees || []).includes(w.name));
      const candidates = [mainSha, ...(item?.refs?.preservedIn || [])];
      w.notPreserved = filesNotPreserved(w.path, files, candidates);
      w.preservedRefs = candidates;
    }
  }
  return out;
}

// Un fichier modifié localement est « sauvegardé dans Git » si son contenu (fins de ligne
// normalisées) existe à l'identique dans l'une des refs candidates (main, ou les refs déclarées
// dans le registre, ex. un instantané). Un fichier supprimé l'est s'il est absent de l'une d'elles.
function filesNotPreserved(wtPath, files, refs) {
  const norm = (b) => b.toString('utf8').replace(/\r\n/g, '\n');
  const queries = [];
  for (const f of files) for (const r of refs) queries.push(`${r}:${f.file}`);
  const res = catFileBatch(queries);
  const missing = [];
  for (const f of files) {
    const abs = path.join(wtPath, f.file);
    const exists = fs.existsSync(abs) && fs.statSync(abs).isFile();
    const local = exists ? norm(fs.readFileSync(abs)) : null;
    const ok = refs.some((r) => {
      const blob = res.get(`${r}:${f.file}`);
      if (!exists) return blob === undefined;
      return blob !== undefined && norm(blob) === local;
    });
    if (!ok) missing.push(f.file);
  }
  return missing;
}
function catFileBatch(queries) {
  const map = new Map();
  if (!queries.length) return map;
  const r = spawnSync('git', ['cat-file', '--batch'], { cwd: repo, input: queries.join('\n') + '\n', maxBuffer: 1024 * 1024 * 1024 });
  const buf = r.stdout; let pos = 0;
  for (const q of queries) {
    const nl = buf.indexOf(10, pos); const header = buf.slice(pos, nl).toString('utf8'); pos = nl + 1;
    if (header.endsWith(' missing') || header.endsWith(' ambiguous')) continue;
    const size = Number(header.split(' ')[2]);
    const type = header.split(' ')[1];
    if (type === 'blob') map.set(q, buf.slice(pos, pos + size));
    pos += size + 1;
  }
  return map;
}

function collectRelease(rev, prodTag, branches, mainSha) {
  const sha = git(['rev-parse', `${rev}^{commit}`]).trim();
  const base = prodTag ? prodTag.sha : null;
  const files = base ? lines(git(['diff', '--name-only', `${base}..${sha}`])) : [];
  const fileSet = new Set(files);
  const overlaps = branches.map((b) => {
    const mb = (gitTry(['merge-base', mainSha, b.sha]) || '').trim();
    const bf = mb ? lines(git(['diff', '--name-only', `${mb}..${b.sha}`])) : [];
    return { branch: b.name, files: bf.filter((f) => fileSet.has(f)) };
  }).filter((o) => o.files.length);
  return { sha, onMain: gitOk(['merge-base', '--is-ancestor', sha, mainSha]), files: files.length, overlaps };
}

// ── PR ─────────────────────────────────────────────────────────────────────────
async function collectPrs(registry, g) {
  const settings = registry.settings || {};
  const slug = settings.github || registry.github;
  const prs = new Map();
  let source = 'git';
  if (slug && !opt.offline && typeof fetch === 'function') {
    try {
      const headers = { Accept: 'application/vnd.github+json', 'User-Agent': 'surgicalhub-work-inventory' };
      if (process.env.GITHUB_TOKEN) headers.Authorization = `Bearer ${process.env.GITHUB_TOKEN}`;
      for (let page = 1; page <= 10; page++) {
        const r = await fetch(`https://api.github.com/repos/${slug}/pulls?state=all&per_page=100&page=${page}`, { headers, signal: AbortSignal.timeout(15000) });
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const data = await r.json();
        for (const p of data) prs.set(p.number, { number: p.number, state: p.merged_at ? 'merged' : p.state, headRef: p.head.ref, head: p.head.sha,
          title: p.title, createdAt: p.created_at, mergedAt: p.merged_at, closedAt: p.closed_at, draft: !!p.draft });
        if (data.length < 100) break;
      }
      source = 'github';
    } catch (e) { prs.clear(); source = `git (API GitHub indisponible : ${e.message})`; }
  }
  if (source !== 'github') {
    const out = gitTry(['ls-remote', g.remote, 'refs/pull/*/head']) || '';
    for (const l of lines(out)) {
      const [sha, ref] = l.split('\t'); const n = Number(ref.split('/')[2]);
      const known = gitOk(['cat-file', '-e', `${sha}^{commit}`]);
      let state = 'inconnu';
      if (known) {
        if (gitOk(['merge-base', '--is-ancestor', sha, g.mainSha])) state = 'merged';
        else state = lines(git(['cherry', g.mainSha, sha])).some((c) => c.startsWith('+ ')) ? 'non-fusionnee' : 'merged';
      }
      prs.set(n, { number: n, state, head: sha, headRef: null, title: null, createdAt: null, unverified: state !== 'merged' });
    }
  }
  return { list: [...prs.values()].sort((a, b) => b.number - a.number), source };
}

// ── Serveur (lecture seule) ────────────────────────────────────────────────────
const SERVER_SCRIPT = String.raw`
set +e
A="$APP_ROOT"; D="${'$'}{DOCKER:-docker}"
echo "@@release $(basename "$(readlink "$A/current" 2>/dev/null)" 2>/dev/null)"
echo "@@revision $(tr -d '\r\n ' < "$A/current/backend/REVISION" 2>/dev/null)"
$D exec "$PHP" php bin/console app:release:show 2>/dev/null | sed -n 's/^ *Statut *\([A-Z_]*\).*/@@relstatus \1/p; s/^ *Version officielle *\([^ ]*\).*/@@reltag \1/p; s/^ *Commit exécuté *\([0-9a-f]*\).*/@@relcommit \1/p'
$D exec "$PHP" php bin/console doctrine:migrations:status --env=prod 2>/dev/null | sed -n 's/.*| *Executed Unavailable *| *\([0-9]*\).*/@@mig unavailable \1/p; s/.*| *Executed *| *\([0-9]*\).*/@@mig executed \1/p; s/.*| *New *| *\([0-9]*\).*/@@mig new \1/p; s/.*| *Available *| *\([0-9]*\).*/@@mig available \1/p'
for f in $SCRIPTS; do if [ -f "$f" ]; then echo "@@script $f $(tr -d '\r' < "$f" | sha256sum | cut -d' ' -f1)"; else echo "@@script $f MISSING"; fi; done
for d in $WATCH; do [ -d "$d" ] && find "$d" -maxdepth 2 -type f -printf '@@listing %p\n'; done
echo "@@crontab"; crontab -l 2>/dev/null; echo "@@endcrontab"
for c in $CONTAINERS; do echo "@@container $c $($D inspect -f '{{.State.Status}} {{.RestartCount}}' "$c" 2>/dev/null || echo absent)"; done
echo "@@end"
`;

function collectServer(g) {
  const manifestPath = path.join(repo, 'deploy', 'production', 'server-reference', 'manifest.json');
  if (!fs.existsSync(manifestPath)) return { status: 'Non vérifié', reason: `manifeste absent (${path.relative(repo, manifestPath)})` };
  const m = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const sshCmd = (process.env.INVENTORY_SSH || `ssh -o ConnectTimeout=20 -o BatchMode=yes ${m.host}`).split(' ').filter(Boolean);
  const q = (s) => `'${String(s).replace(/'/g, `'\\''`)}'`;
  const env = [
    `APP_ROOT=${q(m.app.root)}`, `PHP=${q(m.app.phpContainer)}`, `SCRIPTS=${q(m.scripts.map((s) => s.path).join(' '))}`,
    `WATCH=${q((m.watchDirs || []).join(' '))}`, `CONTAINERS=${q(m.app.containers.join(' '))}`,
  ].join(' ');
  const r = spawnSync(sshCmd[0], [...sshCmd.slice(1), `${env} bash -s`], { input: SERVER_SCRIPT, encoding: 'utf8', timeout: 120000, maxBuffer: 64 * 1024 * 1024 });
  if (r.status !== 0 || !/@@end/.test(r.stdout || '')) return { status: 'Non vérifié', reason: `serveur injoignable ou sortie incomplète (code ${r.status}) ${(r.stderr || '').trim().split('\n')[0] || ''}`.trim() };
  const out = { status: 'vérifié', scripts: [], unlisted: [], containers: [], migrations: {}, crontab: { added: [], removed: [] } };
  const cron = []; let inCron = false; const listed = [];
  for (const l of (r.stdout || '').split('\n').map((x) => x.replace(/\r$/, ''))) {
    if (l === '@@crontab') { inCron = true; continue; }
    if (l === '@@endcrontab') { inCron = false; continue; }
    if (inCron) { cron.push(l); continue; }
    const [tag, ...rest] = l.split(' ');
    if (tag === '@@release') out.release = rest.join(' ') || null;
    else if (tag === '@@revision') out.revision = rest.join(' ') || null;
    else if (tag === '@@relstatus') out.releaseStatus = rest[0];
    else if (tag === '@@reltag') out.releaseTag = rest[0];
    else if (tag === '@@relcommit') out.releaseCommit = rest[0];
    else if (tag === '@@mig') out.migrations[rest[0]] = Number(rest[1]);
    else if (tag === '@@script') out.scripts.push({ path: rest[0], actual: rest[1] === 'MISSING' ? null : rest[1] });
    else if (tag === '@@listing') listed.push(rest.join(' '));
    else if (tag === '@@container') out.containers.push({ name: rest[0], state: rest[1], restarts: rest[2] === undefined ? null : Number(rest[2]) });
  }
  // Scripts : versionnés (comparés au contenu de main) ou hors dépôt (comparés à l'empreinte de référence).
  for (const s of out.scripts) {
    const ref = m.scripts.find((x) => x.path === s.path);
    s.source = ref.source || null; s.note = ref.note || null; s.item = ref.item || null;
    if (ref.source) {
      const blob = gitTry(['show', `${g.mainSha}:${ref.source}`]);
      s.expected = blob === null ? null : crypto.createHash('sha256').update(blob.replace(/\r/g, '')).digest('hex');
      s.state = s.expected === null ? 'source absente de main' : s.actual === null ? 'non installé' : s.actual === s.expected ? 'conforme' : 'différent de Git';
    } else {
      s.expected = ref.sha256 || null;
      s.state = s.actual === null ? (ref.optional ? 'absent (facultatif)' : 'absent du serveur') : s.actual === s.expected ? 'conforme à la référence (hors dépôt)' : 'modifié hors dépôt';
    }
  }
  const known = new Set(m.scripts.map((s) => s.path));
  out.unlisted = listed.filter((p) => !known.has(p)).sort();
  // Crontab : comparaison ligne à ligne avec la référence versionnée.
  const normCron = (ls) => ls.map((l) => l.replace(/\s+$/, '')).filter((l) => l.trim() !== '');
  const refCron = normCron(fs.readFileSync(path.join(repo, m.crontabReference), 'utf8').split('\n').map((l) => l.replace(/\r$/, '')));
  const live = normCron(cron);
  out.crontab.added = live.filter((l) => !refCron.includes(l));
  out.crontab.removed = refCron.filter((l) => !live.includes(l));
  out.prodTagSha = g.prodTag?.sha || null;
  out.releaseMatchesTag = !!(out.release && g.prodTag && out.release === g.prodTag.sha);
  out.revisionMatchesRelease = !!(out.release && out.revision === out.release);
  return out;
}

// ── Analyse : croisement avec le registre ──────────────────────────────────────
function analyse(registry, g, prs, server) {
  const items = registry.items || [];
  const settings = registry.settings || {};
  const staleDays = settings.staleDays ?? 3;
  const refIndex = { branches: new Map(), prs: new Map(), worktrees: new Map(), commits: [] };
  for (const it of items) {
    for (const b of it.refs?.branches || []) refIndex.branches.set(b, it.id);
    for (const p of it.refs?.prs || []) refIndex.prs.set(Number(p), it.id);
    for (const w of it.refs?.worktrees || []) refIndex.worktrees.set(w, it.id);
    for (const c of it.refs?.commits || []) refIndex.commits.push([c, it.id]);
  }
  const prByNumber = new Map(prs.list.map((p) => [p.number, p]));
  const inMain = (sha) => gitOk(['merge-base', '--is-ancestor', sha, g.mainSha]);
  const inProd = (sha) => !!g.prodTag && gitOk(['merge-base', '--is-ancestor', sha, g.prodTag.sha]);
  const inRelease = (sha) => !!g.release && gitOk(['merge-base', '--is-ancestor', sha, g.release.sha]);
  const resolve = (c) => gitTry(['rev-parse', '--verify', '--quiet', `${c}^{commit}`])?.trim() || null;

  const derived = items.map((it) => {
    const d = { id: it.id, warnings: [] };
    const branchStates = (it.refs?.branches || []).map((b) => {
      const br = g.branches.find((x) => x.name === b);
      if (br) return { name: b, unique: br.commits.length, allProbable: br.allProbable, sha: br.sha, behind: br.behind, conflicts: br.conflicts, pushed: br.hasRemote, date: br.date };
      const exists = gitOk(['rev-parse', '--verify', '--quiet', `refs/heads/${b}`]) || gitOk(['rev-parse', '--verify', '--quiet', `refs/remotes/${g.remote}/${b}`]);
      return { name: b, unique: 0, exists };
    });
    const commitStates = (it.refs?.commits || []).map((c) => { const sha = resolve(c); return { ref: c, sha, inMain: sha ? inMain(sha) : null, inProd: sha ? inProd(sha) : null, inRelease: sha ? inRelease(sha) : null }; });
    const prStates = (it.refs?.prs || []).map((n) => {
      const p = prByNumber.get(Number(n)) || { number: Number(n), state: 'inconnu' };
      let prodState = null;
      if (p.state === 'merged' && p.head && gitOk(['cat-file', '-e', `${p.head}^{commit}`])) prodState = inProd(p.head);
      return { ...p, inProd: prodState };
    });
    d.branches = branchStates; d.commits = commitStates; d.prs = prStates;
    const notMerged = branchStates.some((b) => b.unique > 0 && !b.allProbable) || prStates.some((p) => p.state === 'open' || p.state === 'non-fusionnee') || commitStates.some((c) => c.sha && !c.inMain);
    const shas = [...commitStates.filter((c) => c.sha).map((c) => c.sha), ...prStates.filter((p) => p.state === 'merged' && p.head).map((p) => p.head)];
    const allInProd = shas.length > 0 && shas.every((s) => inProd(s));
    d.code = notMerged ? 'non fusionné' : shas.length ? (allInProd ? 'dans le tag de prod' : 'fusionné, absent du tag de prod') : 'aucune référence de code';
    d.inRelease = g.release ? (shas.length > 0 && !notMerged && shas.every((s) => inRelease(s))) : null;
    // Cohérence statut déclaré / état calculé.
    if (['fusionne', 'deploye', 'valide-production'].includes(it.status) && notMerged) d.warnings.push(`statut « ${STATUSES[it.status]} » mais du code n'est pas dans main`);
    if (['deploye', 'valide-production'].includes(it.status) && shas.length && !allInProd) d.warnings.push(`statut « ${STATUSES[it.status]} » mais le code n'est pas dans le tag de prod ${g.prodTag?.name || ''}`.trim());
    if (it.status === 'pr-ouverte' && prStates.length && prStates.every((p) => p.state === 'merged')) d.warnings.push('statut « PR ouverte » mais la PR est fusionnée : mettre à jour le registre');
    if (['pr-ouverte', 'teste', 'developpe', 'en-developpement'].includes(it.status) && !notMerged && shas.length) d.warnings.push(`statut « ${STATUSES[it.status]} » mais tout le code est déjà dans main`);
    for (const b of branchStates) if (b.exists === false) d.warnings.push(`branche « ${b.name} » introuvable`);
    for (const c of commitStates) if (!c.sha) d.warnings.push(`commit « ${c.ref} » introuvable`);
    const reviewAge = daysSince(it.lastReviewed);
    if (reviewAge !== null && reviewAge > (settings.reviewMaxDays ?? 14) && !['valide-production', 'abandonne'].includes(it.status)) d.warnings.push(`registre non revu depuis ${reviewAge} jours`);
    // Correctif oublié : bug utilisateur prêt (ou presque) et toujours absent de la prod.
    const age = daysSince(it.statusSince) ?? Math.max(-1, ...branchStates.filter((b) => b.date).map((b) => daysSince(b.date)));
    d.urgent = ['P0', 'P1'].includes(it.priority) && NOT_IN_PROD_YET.includes(it.status) && d.code !== 'dans le tag de prod';
    d.forgotten = d.urgent && age !== null && age >= staleDays;
    d.age = age;
    d.openOps = (it.pendingOps || []).filter((op) => op.status === 'en-attente' || op.status === 'en-cours');
    return d;
  });

  // Travaux détectés sans entrée de registre.
  const untracked = [];
  for (const b of g.branches) {
    if (refIndex.branches.has(b.name)) continue;
    untracked.push({ kind: b.allProbable ? 'branche-probablement-integree' : 'branche', ref: b.name, detail: `${b.commits.length} commit(s) absents de main${b.allProbable ? ' (sujets identiques dans main)' : ''}${b.hasRemote ? '' : ', JAMAIS POUSSÉE'}` });
  }
  for (const p of prs.list) {
    if ((p.state === 'open' || p.state === 'non-fusionnee') && !refIndex.prs.has(p.number)) untracked.push({ kind: 'pr', ref: `#${p.number}`, detail: p.title || p.head });
  }
  for (const w of g.worktrees) {
    if (w.missing || !w.dirty?.length) continue;
    if (refIndex.worktrees.has(w.name)) continue;
    untracked.push({ kind: 'worktree', ref: w.name, detail: `${w.dirty.length} modification(s) non commitée(s), dont ${w.notPreserved.length} absente(s) de Git` });
  }
  for (const w of g.worktrees) if (w.orphanHead && !refIndex.worktrees.has(w.name)) untracked.push({ kind: 'worktree-detache', ref: w.name, detail: `HEAD ${short(w.head)} contenu dans aucune branche` });
  for (const s of g.stashes) untracked.push({ kind: 'stash', ref: s.ref, detail: s.subject });
  const appNotInProd = g.mainNotInProd.filter((c) => c.app);
  for (const c of appNotInProd) {
    const tracked = refIndex.commits.some(([ref]) => { const sha = resolve(ref); return sha && (sha === c.sha || gitOk(['merge-base', '--is-ancestor', c.sha, sha])); })
      || g.mergedPrsNotInProd.some((m) => m.pr && refIndex.prs.has(m.pr) && gitOk(['merge-base', '--is-ancestor', c.sha, m.sha]));
    if (!tracked) untracked.push({ kind: 'commit-main', ref: short(c.sha), detail: `fusionné, non déployé : ${c.subject}` });
  }
  if (server?.status === 'vérifié') {
    const trackedPaths = new Set(items.flatMap((it) => it.refs?.serverPaths || []));
    for (const s of server.scripts) if (!/^conforme/.test(s.state) && !s.state.startsWith('absent (') && !trackedPaths.has(s.path) && !s.item) untracked.push({ kind: 'serveur', ref: s.path, detail: s.state });
    for (const p of server.unlisted) untracked.push({ kind: 'serveur', ref: p, detail: 'fichier présent sur le serveur, absent du manifeste' });
    for (const l of server.crontab.added) untracked.push({ kind: 'crontab', ref: '+', detail: l });
    for (const l of server.crontab.removed) untracked.push({ kind: 'crontab', ref: '−', detail: l });
  }
  return { derived, untracked, staleDays };
}

// ── Instantané : nouveautés depuis le dernier audit ────────────────────────────
function snapshotKeys(g, prs, server, a) {
  const keys = [];
  for (const b of g.branches) for (const c of b.commits) keys.push(`commit:${b.name}:${c.sha}`);
  for (const p of prs.list) if (p.state === 'open' || p.state === 'non-fusionnee') keys.push(`pr:${p.number}`);
  for (const w of g.worktrees) if (w.dirty?.length) for (const f of w.notPreserved || []) keys.push(`dirty:${w.name}:${f}`);
  for (const c of g.mainNotInProd) keys.push(`main:${c.sha}`);
  for (const u of a.untracked) keys.push(`untracked:${u.kind}:${u.ref}:${u.detail}`);
  if (server?.status === 'vérifié') {
    for (const s of server.scripts) keys.push(`script:${s.path}:${s.state}:${s.actual}`);
  }
  for (const d of a.derived) for (const op of d.openOps) keys.push(`op:${d.id}:${op.description}`);
  return [...new Set(keys)];
}
function snapshotFile() {
  if (process.env.INVENTORY_SNAPSHOT) return process.env.INVENTORY_SNAPSHOT;
  const common = path.resolve(repo, git(['rev-parse', '--git-common-dir']).trim());
  return path.join(common, 'work-inventory', 'last.json');
}

// ── Rendu ──────────────────────────────────────────────────────────────────────
function render(registry, g, prs, server, a, news, regErrors) {
  const out = [];
  const p = (s = '') => out.push(s);
  const items = registry.items || [];
  const byId = new Map(items.map((it) => [it.id, it]));
  const prio = (id) => PRIORITIES.indexOf(byId.get(id)?.priority ?? 'P3');
  p(`# Travaux en attente — SurgicalHub (${NOW.toISOString().slice(0, 16).replace('T', ' ')} UTC)`);
  p();
  p(`- main : \`${short(g.mainSha)}\` (${g.mainRef}) · tag de prod : ${g.prodTag ? `\`${g.prodTag.name}\` → \`${short(g.prodTag.sha)}\`` : 'aucun'}`);
  p(`- PR : source ${prs.source} · serveur : ${server ? (server.status === 'vérifié' ? 'vérifié (lecture seule)' : `Non vérifié — ${server.reason}`) : 'Non vérifié (option --server non utilisée)'}`);
  if (g.release) p(`- **Release prévue : \`${short(g.release.sha)}\`**${g.release.onMain ? '' : ' — ATTENTION : absente de main'} (${g.release.files} fichier(s) modifiés depuis la prod)`);
  if (regErrors.length) { p(); p(`## ⛔ Registre invalide (${regErrors.length})`); regErrors.forEach((e) => p(`- ${e}`)); }

  const urgent = a.derived.filter((d) => d.urgent).sort((x, y) => prio(x.id) - prio(y.id));
  p(); p(`## 1. Correctifs P0/P1 absents de la production (${urgent.length})`);
  if (!urgent.length) p('Aucun.');
  for (const d of urgent) {
    const it = byId.get(d.id);
    const rel = g.release ? (d.inRelease ? ' · inclus dans la release prévue' : ' · **ABSENT DE LA RELEASE PRÉVUE**') : '';
    p(`- ${d.forgotten ? '**OUBLIÉ** ' : ''}**${it.priority} ${it.id}** — ${it.title} · ${STATUSES[it.status]}${d.age !== null && d.age >= 0 ? ` depuis ${d.age} j` : ''} · code : ${d.code}${rel}`);
    p(`  - Impact : ${it.userImpact}`);
    p(`  - Prochaine action : ${it.nextAction}${it.blockedReason ? ` · Blocage : ${it.blockedReason}` : ''}`);
  }

  p(); p(`## 2. Travaux non suivis par le registre (${a.untracked.length})`);
  if (!a.untracked.length) p('Aucun.');
  const kindLabel = { branche: 'Branche', 'branche-probablement-integree': 'Branche (probablement intégrée autrement)', pr: 'PR', worktree: 'Worktree', 'worktree-detache': 'Worktree détaché', stash: 'Stash', 'commit-main': 'Commit de main', serveur: 'Serveur', crontab: 'Crontab' };
  for (const u of a.untracked) p(`- ${kindLabel[u.kind] || u.kind} \`${u.ref}\` — ${u.detail}`);
  if (opt.summary) return finishSummary();

  const app = g.mainNotInProd.filter((c) => c.app); const other = g.mainNotInProd.filter((c) => !c.app);
  p(); p(`## 3. Fusionné dans main, absent de la production (${app.length} applicatif, ${other.length} hors archive)`);
  for (const c of app) p(`- \`${short(c.sha)}\` ${c.subject}`);
  if (other.length) p(`- Hors archive applicative (docs, scripts serveur — rien à déployer par deploy.sh) : ${other.map((c) => `\`${short(c.sha)}\` ${c.subject}`).join(' ; ')}`);
  if (g.migrations.notInProd.length) p(`- **Migrations fusionnées non déployées** : ${g.migrations.notInProd.join(', ')}`);
  if (!g.mainNotInProd.length) p('Rien : main = production.');

  const open = prs.list.filter((x) => x.state === 'open' || x.state === 'non-fusionnee');
  p(); p(`## 4. PR non fusionnées (${open.length})`);
  for (const x of open) {
    const it = byId.get(prRefItem(items, x.number));
    p(`- #${x.number} ${x.title || ''}${x.createdAt ? ` · ouverte depuis ${daysSince(x.createdAt)} j` : ''}${x.unverified ? ' · état exact non vérifié (sans API GitHub)' : ''}${it ? ` · ${it.id} ${it.priority} ${STATUSES[it.status]}` : ' · **non suivie**'}`);
  }
  const closedUnmerged = prs.list.filter((x) => x.state === 'closed');
  if (closedUnmerged.length) p(`- Fermées sans fusion : ${closedUnmerged.map((x) => `#${x.number}`).join(', ')}`);

  p(); p(`## 5. Branches non intégrées (${g.branches.length})`);
  const sorted = [...g.branches].sort((x, y) => (x.date < y.date ? 1 : -1));
  for (const b of sorted) {
    const age = daysSince(b.date);
    const cls = b.allProbable ? 'probablement intégrée autrement' : age <= 14 ? 'récente' : age <= 30 ? 'en attente' : 'ancienne avec travail inédit';
    const flags = [b.hasRemote ? null : '**jamais poussée (ce poste seulement)**', b.localAhead ? 'commits locaux non poussés' : null,
      b.conflicts === null ? 'conflits : non vérifié' : b.conflicts.length ? `conflits avec main : ${b.conflicts.length} fichier(s)` : 'sans conflit',
      b.migrations.length ? `migrations : ${b.migrations.join(', ')}` : null, `${b.behind} commit(s) de retard`].filter(Boolean);
    const it = refIndex(items, 'branches', b.name);
    p(`- \`${b.name}\` — ${b.commits.length} commit(s) inédit(s), dernier il y a ${age} j (${cls}) · ${flags.join(' · ')}${it ? ` · ${it}` : ' · **non suivie**'}`);
  }

  p(); p('## 6. Modifications locales, stashes, worktrees');
  const dirty = g.worktrees.filter((w) => w.dirty?.length);
  if (!dirty.length) p('Aucune modification non commitée.');
  for (const w of dirty) {
    p(`- \`${w.path}\` (${w.branch || `détaché ${short(w.head)}`}) : ${w.dirty.length} modification(s) ; **${w.notPreserved.length} absente(s) de Git** (comparé à ${w.preservedRefs.map((r) => (/^[0-9a-f]{40}$/.test(r) ? short(r) : r)).join(', ')})${refIndex(items, 'worktrees', w.name) ? ` · ${refIndex(items, 'worktrees', w.name)}` : ' · **non suivi**'}`);
    for (const f of w.notPreserved.slice(0, 10)) p(`  - ${f}`);
    if (w.notPreserved.length > 10) p(`  - … et ${w.notPreserved.length - 10} autre(s)`);
  }
  const detached = g.worktrees.filter((w) => w.detached);
  for (const w of detached) p(`- Worktree détaché \`${w.name}\` @ \`${short(w.head)}\` : ${w.inMain ? 'contenu dans main' : w.orphanHead ? '**HEAD dans aucune branche**' : 'hors main'}`);
  const missing = g.worktrees.filter((w) => w.missing);
  for (const w of missing) p(`- Worktree enregistré mais dossier absent : \`${w.path}\``);
  p(`- Stashes : ${g.stashes.length ? g.stashes.map((s) => `${s.ref} ${s.subject}`).join(' ; ') : 'aucun'}`);
  if (g.unreachable) p(`- Commits inaccessibles (--deep) : ${g.unreachable.length}${g.unreachable.length ? ` — plus récents : ${g.unreachable.slice(0, 5).map((c) => `\`${short(c.sha)}\` ${c.subject}`).join(' ; ')}` : ''}`);

  p(); p('## 7. Serveur de production');
  if (!server) p('Non vérifié (lancer avec --server).');
  else if (server.status !== 'vérifié') p(`Non vérifié — ${server.reason}`);
  else {
    p(`- Release active \`${short(server.release)}\`, REVISION \`${short(server.revision)}\`, registre : ${server.releaseStatus || '?'} ${server.releaseTag || ''} — ${server.releaseMatchesTag ? 'conforme au tag de prod' : `**différente du tag de prod (${short(server.prodTagSha)})**`}${server.revisionMatchesRelease ? '' : ' · **REVISION ≠ release**'}`);
    const mg = server.migrations;
    p(`- Migrations : ${mg.executed ?? '?'} exécutées, ${mg.new ?? '?'} nouvelles, ${mg.unavailable ?? '?'} indisponibles${mg.new > 0 ? ' — **MIGRATIONS NON APPLIQUÉES**' : ''}${mg.unavailable > 0 ? ' — **ANOMALIE : migrations exécutées absentes du code**' : ''}`);
    for (const s of server.scripts) if (!/^conforme/.test(s.state)) p(`- Script \`${s.path}\` : **${s.state}**${s.source ? ` (source ${s.source})` : ''}${s.note ? ` — ${s.note}` : ''}`);
    p(`- Scripts conformes : ${server.scripts.filter((s) => /^conforme/.test(s.state)).length}/${server.scripts.length}`);
    for (const f of server.unlisted) p(`- Fichier serveur absent du manifeste : \`${f}\``);
    if (server.crontab.added.length || server.crontab.removed.length) {
      p('- **Crontab différente de la référence versionnée** :');
      server.crontab.added.forEach((l) => p(`  - \`+ ${l}\``)); server.crontab.removed.forEach((l) => p(`  - \`− ${l}\``));
    } else p('- Crontab identique à la référence versionnée.');
    p(`- Conteneurs : ${server.containers.map((c) => `${c.name} ${c.state}`).join(', ')}`);
  }

  const ops = a.derived.flatMap((d) => d.openOps.map((op) => ({ d, op }))).sort((x, y) => prio(x.d.id) - prio(y.d.id));
  p(); p(`## 8. Opérations hors code en attente (${ops.length})`);
  for (const { d, op } of ops) {
    const it = byId.get(d.id);
    p(`- **${OP_TYPES[op.type]}** — ${it.id} ${it.priority} : ${op.description}${op.validation ? ` · validation : ${op.validation}` : ''} · ${op.status === 'en-cours' ? 'en cours' : 'en attente'} · Non vérifiable automatiquement${op.check ? ` (contrôle : ${op.check})` : ''}`);
  }

  p(); p('## 9. Registre');
  const counts = {}; for (const it of items) counts[it.status] = (counts[it.status] || 0) + 1;
  p(`- ${items.length} élément(s) : ${Object.entries(counts).map(([s, n]) => `${STATUSES[s] || s} ${n}`).join(' · ')}`);
  const warn = a.derived.filter((d) => d.warnings.length);
  for (const d of warn) p(`- ${d.id} : ${d.warnings.join(' ; ')}`);
  const blocked = items.filter((it) => it.status === 'bloque');
  for (const it of blocked) p(`- Bloqué : ${it.id} — ${it.blockedReason}`);
  if (g.release?.overlaps.length) {
    p(); p('## 10. Risques de conflit avec la release prévue');
    for (const o of g.release.overlaps) p(`- \`${o.branch}\` touche aussi : ${o.files.slice(0, 8).join(', ')}${o.files.length > 8 ? ` (+${o.files.length - 8})` : ''}`);
  }
  p(); p(`## Nouveau depuis le dernier audit${news.previous ? ` (${news.previous})` : ''}`);
  if (!news.previous) p('Premier audit sur ce poste : pas de comparaison.');
  else if (!news.added.length) p('Rien de nouveau.');
  else news.added.slice(0, 40).forEach((k) => p(`- ${k}`));
  if (news.added.length > 40) p(`- … et ${news.added.length - 40} autre(s)`);
  if (news.previous && news.removed.length) p(`- Disparu depuis : ${news.removed.length} élément(s) (intégré, déployé ou supprimé)`);
  return out.join('\n');

  function finishSummary() {
    const ops2 = a.derived.reduce((n, d) => n + d.openOps.length, 0);
    p(); p(`Résumé : ${g.mainNotInProd.filter((c) => c.app).length} commit(s) applicatif(s) fusionné(s) non déployé(s), ${prs.list.filter((x) => x.state === 'open' || x.state === 'non-fusionnee').length} PR ouverte(s), ${g.branches.length} branche(s) non intégrée(s), ${g.worktrees.filter((w) => w.notPreserved?.length).length} worktree(s) avec du travail hors Git, ${ops2} opération(s) hors code en attente. Rapport complet : deploy/deploy.sh pending.`);
    return out.join('\n');
  }
}
function refIndex(items, kind, value) { const it = items.find((x) => (x.refs?.[kind] || []).includes(value)); return it ? `${it.id} ${it.priority} ${STATUSES[it.status]}` : null; }
function prRefItem(items, n) { return items.find((x) => (x.refs?.prs || []).map(Number).includes(n))?.id; }

// ── Principal ──────────────────────────────────────────────────────────────────
const { registry, errors: regErrors } = loadRegistry();
if (opt.checkOnly) {
  if (regErrors.length) { console.error(`Registre invalide (${regErrors.length}) :\n- ${regErrors.join('\n- ')}`); process.exit(1); }
  console.log(`Registre valide : ${registry.items.length} élément(s).`); process.exit(0);
}
const g = collectGit(registry);
const prs = await collectPrs(registry, g);
const server = opt.server ? collectServer(g) : null;
const a = analyse(registry, g, prs, server);
const keys = snapshotKeys(g, prs, server, a);
const snapFile = snapshotFile();
let news = { previous: null, added: [], removed: [] };
if (fs.existsSync(snapFile)) {
  try {
    const prev = JSON.parse(fs.readFileSync(snapFile, 'utf8'));
    const old = new Set(prev.keys); const cur = new Set(keys);
    news = { previous: prev.generatedAt, added: keys.filter((k) => !old.has(k)), removed: prev.keys.filter((k) => !cur.has(k)) };
  } catch { /* instantané illisible : ignoré */ }
}
if (opt.snapshot) { fs.mkdirSync(path.dirname(snapFile), { recursive: true }); fs.writeFileSync(snapFile, JSON.stringify({ generatedAt: NOW.toISOString(), keys }, null, 1)); }

if (opt.json) console.log(JSON.stringify({ generatedAt: NOW.toISOString(), git: g, prs, server, analysis: a, news, registryErrors: regErrors }, null, 2));
else console.log(render(registry, g, prs, server, a, news, regErrors));

const fail = [];
if (opt.failOn.includes('registry') && regErrors.length) fail.push('registre invalide');
if (opt.failOn.includes('untracked') && a.untracked.length) fail.push(`${a.untracked.length} travail(aux) non suivi(s)`);
if (opt.failOn.includes('urgent') && a.derived.some((d) => d.forgotten || (g.release && d.urgent && !d.inRelease))) fail.push('correctif P0/P1 oublié');
if (opt.failOn.includes('server') && server && (server.status !== 'vérifié' || server.scripts.some((s) => !/^conforme|^absent \(/.test(s.state)) || server.crontab.added.length || server.crontab.removed.length || (server.migrations.new > 0))) fail.push('écart serveur');
if (fail.length) { console.error(`\nÉchec demandé (--fail-on) : ${fail.join(', ')}`); process.exit(1); }
