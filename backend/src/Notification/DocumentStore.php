<?php

namespace App\Notification;

use App\Entity\GeneratedDocument;
use App\Enum\DocumentRetentionClass;
use Doctrine\ORM\EntityManagerInterface;

/**
 * H5a (§16) — stores a generated document once and hands out its reference.
 *
 * store() is idempotent on content: the same bytes of the same type under the same
 * filename return the existing row (a Messenger retry of a deploy never stores the same
 * PDF twice). load() verifies the sha256 — an email never carries a document that differs
 * from the one its delivery record names.
 */
class DocumentStore
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * H5b (D-132) — the document that represents $sourceKey for this exact input: the one
     * already stored (and possibly already sent) when the rendered data did not change — its
     * bytes, hence its sha256, are reused; otherwise $render() is called once and stored.
     * A resend therefore never re-renders a document whose content is unchanged, and never
     * presents a new rendering as the one sent earlier.
     *
     * @param callable(): string $render
     */
    public function storeOnce(
        string $documentType,
        string $sourceKey,
        string $inputFingerprint,
        string $filename,
        callable $render,
        DocumentRetentionClass $retention = DocumentRetentionClass::IMMUTABLE_EVIDENCE,
    ): DocumentReference {
        $existing = $this->em->getRepository(GeneratedDocument::class)->findOneBy(
            ['sourceKey' => mb_substr($sourceKey, 0, 120), 'inputFingerprint' => $inputFingerprint],
            ['id' => 'DESC'],
        );
        if ($existing instanceof GeneratedDocument) {
            return DocumentReference::of($existing);
        }

        return $this->store($documentType, $filename, $render(), 'application/pdf', $retention, $sourceKey, $inputFingerprint);
    }

    /** The latest document stored for $sourceKey (whatever its input), e.g. a financial document once sent. */
    public function latestFor(string $sourceKey): ?DocumentReference
    {
        $doc = $this->em->getRepository(GeneratedDocument::class)->findOneBy(['sourceKey' => mb_substr($sourceKey, 0, 120)], ['id' => 'DESC']);

        return $doc instanceof GeneratedDocument ? DocumentReference::of($doc) : null;
    }

    public function store(
        string $documentType,
        string $filename,
        string $content,
        string $contentType = 'application/pdf',
        DocumentRetentionClass $retention = DocumentRetentionClass::IMMUTABLE_EVIDENCE,
        ?string $sourceKey = null,
        ?string $inputFingerprint = null,
    ): DocumentReference {
        $sha256 = hash('sha256', $content);
        $existing = $this->em->getRepository(GeneratedDocument::class)->findOneBy([
            'sha256' => $sha256, 'documentType' => $documentType, 'filename' => $filename,
        ]);
        if ($existing instanceof GeneratedDocument) {
            return DocumentReference::of($existing);
        }

        $document = new GeneratedDocument($documentType, $filename, $content, $contentType, $retention, $sourceKey, $inputFingerprint);
        $this->em->persist($document);
        $this->em->flush();

        return DocumentReference::of($document);
    }

    /** @throws \RuntimeException when the document is missing or its content no longer matches the reference */
    public function load(DocumentReference $reference): GeneratedDocument
    {
        $document = $this->em->find(GeneratedDocument::class, $reference->documentId);
        if (!$document instanceof GeneratedDocument) {
            throw new \RuntimeException(sprintf('Document #%d introuvable.', $reference->documentId));
        }
        if (!hash_equals($reference->sha256, hash('sha256', $document->getContent()))) {
            throw new \RuntimeException(sprintf('Document #%d : empreinte différente de la référence.', $reference->documentId));
        }

        return $document;
    }
}
