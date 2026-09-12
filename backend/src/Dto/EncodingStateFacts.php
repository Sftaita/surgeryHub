<?php

namespace App\Dto;

use App\Enum\MissionStatus;

/**
 * Suivi des encodages (D-092) — les faits bruts, et rien qu'eux, dont
 * EncodingStateResolver a besoin pour dériver un EncodingState.
 *
 * Ce DTO existe pour une raison précise : la dérivation doit rester UNE seule
 * implémentation PHP alors qu'elle est alimentée par deux chemins très différents —
 * la liste paginée (entités Mission hydratées avec fetch-join) et le résumé de période
 * (lignes SQL plates, jamais hydratées, cf. la règle de perf D-077 §22). Sans ce point
 * de rencontre, la seule alternative serait de dupliquer la logique en CASE SQL, ce qui
 * garantirait une divergence à la première évolution.
 */
final readonly class EncodingStateFacts
{
    public function __construct(
        public MissionStatus $status,
        /** Fin PLANIFIÉE (Mission.endAt) — sert uniquement à distinguer UPCOMING de TO_ENCODE. */
        public ?\DateTimeImmutable $endAt,
        public ?\DateTimeImmutable $encodingStartedAt,
        public ?\DateTimeImmutable $invoiceGeneratedAt,
        public int $interventionCount,
        /** Lignes de matériel à quantity > 0 uniquement — même définition d'"active" que
         *  MissionEncodingWorkflowService::countActiveMaterialLines(). */
        public int $activeMaterialLineCount,
        /** MissionExecution existe ET porte au moins une donnée réelle (horaires ou durée). */
        public bool $hasExecutionActuals,
    ) {}
}
