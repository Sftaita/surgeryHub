<?php

namespace App\Service;

use App\Dto\FinancialStatisticsFilter;
use App\Enum\EncodingState;
use App\Enum\MissionType;
use App\Exception\InvalidStatisticsFilterException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Suivi des encodages (D-118) — parsing des paramètres propres au module.
 *
 * Délègue le filtre commun (période, site, chirurgien, instrumentiste, firme, type
 * d'intervention) à FinancialStatisticsRequestParser plutôt que de le réimplémenter :
 * la convention de période (from inclusif / to exclusif, timezone métier D-066, sentinels
 * jamais now()) doit rester identique entre les deux modules, sinon les deux écrans
 * décriraient des périodes différentes sous le même libellé.
 */
final class EncodingTrackingRequestParser
{
    private const MAX_LIMIT = 200;

    public function __construct(private readonly FinancialStatisticsRequestParser $shared) {}

    public function parseFilter(Request $request): FinancialStatisticsFilter
    {
        return $this->shared->parseFilter($request);
    }

    /**
     * `encodingState` accepte une liste séparée par des virgules — la vue "À traiter"
     * en demande plusieurs d'un coup.
     *
     * @return EncodingState[] liste vide = aucun filtre d'état
     */
    public function parseEncodingStates(Request $request): array
    {
        $raw = $request->query->get('encodingState');
        if ($raw === null || $raw === '') {
            return [];
        }

        $states = [];
        foreach (explode(',', (string) $raw) as $candidate) {
            $candidate = strtoupper(trim($candidate));
            if ($candidate === '') {
                continue;
            }

            $state = EncodingState::tryFrom($candidate);
            if ($state === null) {
                throw new InvalidStatisticsFilterException(sprintf(
                    'encodingState invalide "%s" — valeurs autorisées : %s.',
                    $candidate,
                    implode(', ', array_column(EncodingState::cases(), 'value')),
                ));
            }

            $states[] = $state;
        }

        return array_values(array_unique($states, SORT_REGULAR));
    }

    public function parseMissionType(Request $request): ?MissionType
    {
        $raw = $request->query->get('missionType');
        if ($raw === null || $raw === '') {
            return null;
        }

        $type = MissionType::tryFrom(strtoupper((string) $raw));
        if ($type === null) {
            throw new InvalidStatisticsFilterException(sprintf(
                'missionType invalide — valeurs autorisées : %s.',
                implode(', ', array_column(MissionType::cases(), 'value')),
            ));
        }

        return $type;
    }

    /** @return array{page: int, limit: int} */
    public function parsePagination(Request $request): array
    {
        return [
            'page' => max(1, (int) $request->query->get('page', 1)),
            'limit' => max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', 50))),
        ];
    }
}
