<?php

namespace App\Notification;

use App\Entity\GeneratedDocument;

/**
 * H5a (§16) — how an attachment travels: by reference to a stored GeneratedDocument, never
 * by content. Safe to persist anywhere (Messenger message, outbound payload, activity
 * payload): no bytes, no personal data beyond the filename.
 */
final class DocumentReference
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $documentType,
        public readonly string $filename,
        public readonly string $sha256,
        public readonly int $byteSize,
    ) {
    }

    public static function of(GeneratedDocument $document): self
    {
        return new self((int) $document->getId(), $document->getDocumentType(), $document->getFilename(), $document->getSha256(), $document->getByteSize());
    }

    /** @return array{documentId: int, documentType: string, filename: string, sha256: string, byteSize: int} */
    public function toArray(): array
    {
        return [
            'documentId' => $this->documentId, 'documentType' => $this->documentType,
            'filename' => $this->filename, 'sha256' => $this->sha256, 'byteSize' => $this->byteSize,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['documentId'], (string) $data['documentType'], (string) $data['filename'], (string) $data['sha256'], (int) $data['byteSize']);
    }
}
