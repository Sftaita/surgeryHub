<?php

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Guardrail introduced after discovering `App\Controller\Api\MissionEncodingController`
 * committed at `src/Entity/MissionEncodingController.php` — a namespace/path mismatch that
 * Composer's PSR-4 autoloader silently excludes from the generated classmap (a warning at
 * `composer dump-autoload` time, never a hard error), so the class was never loaded and its
 * `#[Route]` attributes were never registered with Symfony. Nothing that relies on the class
 * actually being autoloadable — `class_exists()`, `ReflectionClass` on a live class, a
 * `glob()`-then-`class_exists()` scan like `BusinessDateTimeColumnConventionTest::entityClasses()`
 * — can ever catch this bug class, because the failure mode *is* "never gets autoloaded". This
 * test instead reads the raw token stream of every PHP file under src/ (never `include`s or
 * `eval`s it, so nothing here is ever autoloaded) to find the namespace and type actually
 * declared in the source, independent of Composer, and compares it against the namespace PSR-4
 * ("App\\" => "src/") requires for that file's physical location.
 */
final class Psr4NamespaceConventionTest extends TestCase
{
    private const ROOT_NAMESPACE = 'App';

    /** @var int[] token ids that declare a type with a name PSR-4 cares about */
    private const TYPE_DECLARING_TOKENS = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    public function test_every_php_file_under_src_declares_its_psr4_expected_namespace(): void
    {
        $srcDir = realpath(__DIR__ . '/../../src');
        self::assertNotFalse($srcDir, 'backend/src not found — check the path.');

        $files = $this->phpFilesRecursively($srcDir);
        self::assertNotEmpty($files, 'No PHP files found under src/ — check the path.');

        $violations = [];

        foreach ($files as $file) {
            $declaration = $this->parseTypeDeclaration($file);
            if ($declaration === null) {
                continue; // no class/interface/trait/enum declared in this file — nothing to check
            }

            [$declaredNamespace, $typeName] = $declaration;
            $expectedNamespace = $this->expectedNamespaceFor($srcDir, $file);

            if ($declaredNamespace === $expectedNamespace) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file, strlen($srcDir) + 1));
            $violations[] = sprintf(
                '%s (type "%s") — namespace attendu "%s", namespace déclaré "%s"',
                $relativePath,
                $typeName,
                $expectedNamespace,
                $declaredNamespace === '' ? '(aucun)' : $declaredNamespace,
            );
        }

        self::assertSame(
            [],
            $violations,
            "Fichier(s) sous backend/src/ dont le namespace déclaré ne correspond pas à l'emplacement " .
            "physique attendu par la règle PSR-4 (\"App\\\\\" => \"src/\") :\n  - " .
            implode("\n  - ", $violations) . "\n" .
            "Composer n'échoue pas fort sur ce cas (simple avertissement au dump-autoload) : la classe " .
            "est silencieusement exclue de la classmap et n'est jamais chargée. Corriger en déplaçant " .
            "le fichier au bon endroit, ou en corrigeant son namespace s'il a été mal déclaré.",
        );
    }

    /** @return list<string> chemins absolus */
    private function phpFilesRecursively(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Lit le flux de tokens du fichier (jamais `include`/`eval`, donc jamais d'autoload) pour
     * trouver le namespace déclaré et le nom du premier type (classe/interface/trait/enum)
     * déclaré. Retourne null si le fichier n'en déclare aucun (ex: fichier de fonctions pures).
     *
     * @return array{0: string, 1: string}|null [namespace, nomDuType]
     */
    private function parseTypeDeclaration(string $file): ?array
    {
        $source = file_get_contents($file);
        if ($source === false) {
            return null;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);

        $namespace = '';
        $typeName = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readQualifiedName($tokens, $i + 1);
                continue;
            }

            if ($typeName !== null || !in_array($token[0], self::TYPE_DECLARING_TOKENS, true)) {
                continue;
            }

            // `Foo::class` tokenizes T_CLASS too — skip it, it isn't a declaration.
            if ($token[0] === T_CLASS) {
                $prev = $this->previousNonWhitespaceToken($tokens, $i);
                if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue;
                }
            }

            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if ($next === '{') {
                    break; // anonymous class or malformed declaration — no name to extract
                }
                if (is_array($next) && $next[0] === T_STRING) {
                    $typeName = $next[1];
                    break;
                }
            }
        }

        return $typeName === null ? null : [$namespace, $typeName];
    }

    /** Concatenates a namespace/name token sequence, handling both pre- and post-PHP-8 tokenizer shapes. */
    private function readQualifiedName(array $tokens, int $start): string
    {
        $name = '';
        for ($j = $start; $j < count($tokens); $j++) {
            $token = $tokens[$j];
            if ($token === ';' || $token === '{') {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED], true)) {
                $name .= $token[1];
            }
        }

        return $name;
    }

    private function previousNonWhitespaceToken(array $tokens, int $index): array|string|null
    {
        for ($j = $index - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private function expectedNamespaceFor(string $srcDir, string $file): string
    {
        $relativeDir = dirname(substr($file, strlen($srcDir) + 1));
        if ($relativeDir === '.') {
            return self::ROOT_NAMESPACE;
        }

        $segments = explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $relativeDir));

        return self::ROOT_NAMESPACE . '\\' . implode('\\', $segments);
    }
}
