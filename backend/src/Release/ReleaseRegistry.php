<?php

namespace App\Release;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * D-162 — quelle version de SurgicalHub tourne réellement, et est-elle la version officielle ?
 *
 * Deux sources, jamais une valeur écrite à la main dans le code :
 *  - le commit EXÉCUTÉ : `backend/REVISION`, rempli par `git archive` (attribut export-subst)
 *    avec le SHA exact du commit archivé — la procédure de déploiement obligatoire
 *    (docs/deployment-versioning.md §4.3). Dans un clone de travail, le fichier garde sa
 *    valeur littérale `$Format:%H$` : c'est un environnement de développement ;
 *  - le registre des versions OFFICIELLES : `var/release/releases.json`, sur le volume
 *    persistant `surgicalhub_var` (survit aux rebuilds et aux rollbacks). Une ligne n'y est
 *    ajoutée qu'après les tests de santé et la création du tag Git
 *    (`app:release:mark <tag> --commit=<sha>`, §7 de la procédure).
 *
 * Une version n'est annoncée officielle que si le commit exécuté figure dans le registre :
 * un déploiement pas encore validé est « en validation », et un rollback retrouve
 * automatiquement le tag du commit restauré. Aucune donnée sensible : un tag et un SHA.
 */
final class ReleaseRegistry
{
    public const STATUS_OFFICIAL = 'OFFICIAL';
    public const STATUS_PENDING = 'PENDING_VALIDATION';
    public const STATUS_DEVELOPMENT = 'DEVELOPMENT';

    /** Format imposé par docs/deployment-versioning.md §1. */
    public const TAG_PATTERN = '/^v\d{4}\.\d{2}\.\d{2}-prod(-[1-9]\d*)?$/';

    private readonly string $revisionFile;
    private readonly string $registryFile;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        ?string $revisionFile = null,
        ?string $registryFile = null,
    ) {
        $this->revisionFile = $revisionFile ?? $projectDir . '/REVISION';
        $this->registryFile = $registryFile ?? $projectDir . '/var/release/releases.json';
    }

    /** SHA complet (40 caractères) du code exécuté, ou null hors d'une archive de déploiement. */
    public function runningCommit(): ?string
    {
        $raw = @file_get_contents($this->revisionFile);
        if ($raw === false) {
            return null;
        }
        $sha = strtolower(trim($raw));

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /**
     * @return array{status: string, version: ?string, commit: ?string}
     */
    public function current(): array
    {
        $commit = $this->runningCommit();
        if ($commit === null) {
            return ['status' => self::STATUS_DEVELOPMENT, 'version' => null, 'commit' => null];
        }

        $tag = null;
        foreach ($this->entries() as $entry) {
            if ($entry['commit'] === $commit) {
                $tag = $entry['tag']; // le plus récent l'emporte (ordre d'ajout)
            }
        }

        return [
            'status' => $tag !== null ? self::STATUS_OFFICIAL : self::STATUS_PENDING,
            'version' => $tag,
            'commit' => substr($commit, 0, 7),
        ];
    }

    /**
     * Enregistre `$tag` comme version officielle du code EXÉCUTÉ. `$expectedCommit` (SHA court
     * ou complet, tel que l'opérateur l'a vérifié sur le tag Git) doit correspondre : on ne
     * marque jamais une autre version que celle qui tourne.
     *
     * @return bool false si l'entrée existait déjà à l'identique (idempotent)
     */
    public function mark(string $tag, string $expectedCommit): bool
    {
        if (preg_match(self::TAG_PATTERN, $tag) !== 1) {
            throw new \InvalidArgumentException(sprintf('Tag « %s » invalide : format attendu vYYYY.MM.DD-prod ou vYYYY.MM.DD-prod-N.', $tag));
        }
        $commit = $this->runningCommit();
        if ($commit === null) {
            throw new \RuntimeException('Commit exécuté inconnu (REVISION absent ou non substitué) : ce code ne provient pas d\'une archive git archive, il ne peut pas être marqué officiel.');
        }
        $expected = strtolower(trim($expectedCommit));
        if (preg_match('/^[0-9a-f]{7,40}$/', $expected) !== 1 || !str_starts_with($commit, $expected)) {
            throw new \RuntimeException(sprintf('Le commit attendu « %s » ne correspond pas au commit exécuté %s : rien n\'est marqué.', $expectedCommit, substr($commit, 0, 7)));
        }

        $entries = $this->entries();
        foreach ($entries as $entry) {
            if ($entry['tag'] === $tag) {
                if ($entry['commit'] === $commit) {
                    return false;
                }
                throw new \RuntimeException(sprintf('Le tag %s est déjà enregistré pour le commit %s : un tag ne change jamais de commit.', $tag, substr($entry['commit'], 0, 7)));
            }
        }

        $entries[] = ['tag' => $tag, 'commit' => $commit, 'markedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
        $this->write($entries);

        return true;
    }

    /** @return list<array{tag: string, commit: string, markedAt: string}> */
    public function entries(): array
    {
        $raw = @file_get_contents($this->registryFile);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['releases']) || !is_array($data['releases'])) {
            throw new \RuntimeException('Registre des versions illisible : ' . $this->registryFile);
        }

        return array_values(array_filter($data['releases'], static fn ($e) => is_array($e)
            && is_string($e['tag'] ?? null) && is_string($e['commit'] ?? null) && is_string($e['markedAt'] ?? null)));
    }

    /** @param list<array{tag: string, commit: string, markedAt: string}> $entries */
    private function write(array $entries): void
    {
        $dir = \dirname($this->registryFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer ' . $dir);
        }
        $tmp = $this->registryFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['releases' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $this->registryFile)) {
            @unlink($tmp);
            throw new \RuntimeException('Écriture impossible : ' . $this->registryFile);
        }
    }
}
