<?php

namespace App\Activity\Governed\Describer;

use App\Activity\ActivityJournal;
use App\Activity\ActivityRecord;
use App\Activity\Governed\AbstractGovernedDescriber;
use App\Activity\Governed\GovernedChange;
use App\Entity\User;
use App\Enum\ActivityEventType;

/**
 * H5b (D-132) — ONLY the legacy rate fields of User (PATCH /api/instrumentists/{id}/rates,
 * kept beside the versioned InstrumentistRate — D-072 known drift risk). Every other change of
 * a User is told by UserAuditEvent (mirrored by H1); a User creation is never an event here.
 */
final class UserLegacyRatesDescriber extends AbstractGovernedDescriber
{
    public function entityClass(): string { return User::class; }

    public function trackedFields(): array
    {
        return ['hourlyRate' => 'Taux horaire (hérité)', 'consultationFee' => 'Forfait consultation (hérité)'];
    }

    public function describe(GovernedChange $c): ?ActivityRecord
    {
        if ($c->action !== GovernedChange::UPDATED) {
            return null;
        }
        /** @var User $u */
        $u    = $c->entity;
        $name = ActivityJournal::displayName($u) ?? '—';

        return $this->record(ActivityEventType::INSTRUMENTIST_LEGACY_RATES_UPDATED, $c, 'User', $name,
            sprintf('%s a modifié les tarifs hérités de %s : %s', self::actorName($c), $name, self::diffText($c)),
            ['userId' => $c->entityId, 'userName' => $name], userId: $c->entityId);
    }
}
