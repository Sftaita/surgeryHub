<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EncodingReminderStage;
use App\Enum\MissionStatus;
use App\Service\EncodingReminderService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * D-143 — calendrier pur de la relance progressive (aucune base) : échéances 09 h
 * Europe/Brussels à J+1/J+2/J+4 de la date LOCALE de fin, fenêtres de 24 h, changements
 * d'heure, statuts encore « en attente d'encodage », textes validés.
 */
final class EncodingReminderServiceTest extends TestCase
{
    private const TZ = 'Europe/Brussels';

    private function mission(string $localEnd, MissionStatus $status = MissionStatus::ASSIGNED, bool $withInstrumentist = true): Mission
    {
        $m = new Mission();
        $end = new \DateTimeImmutable($localEnd, new \DateTimeZone(self::TZ));
        $m->setStartAt($end->modify('-8 hours'));
        $m->setEndAt($end);
        $m->setStatus($status);
        if ($withInstrumentist) {
            $m->setInstrumentist((new User())->setEmail('i@test.com'));
        }

        return $m;
    }

    private function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone(self::TZ));
    }

    public function test_due_dates_are_nine_am_on_the_following_civil_days(): void
    {
        $m = $this->mission('2026-07-14 17:30');

        self::assertSame('2026-07-15T09:00:00+02:00', EncodingReminderService::dueAt($m, EncodingReminderStage::D1)->format('c'));
        self::assertSame('2026-07-16T09:00:00+02:00', EncodingReminderService::dueAt($m, EncodingReminderStage::D2)->format('c'));
        self::assertSame('2026-07-18T09:00:00+02:00', EncodingReminderService::dueAt($m, EncodingReminderStage::D4)->format('c'));
    }

    /** Fin d'heure d'été (25/10/2026 03:00 → 02:00) entre la fin de mission et l'échéance. */
    public function test_autumn_dst_change_keeps_nine_am_local(): void
    {
        $m = $this->mission('2026-10-24 18:00');

        self::assertSame('2026-10-25T09:00:00+01:00', EncodingReminderService::dueAt($m, EncodingReminderStage::D1)->format('c'));
        self::assertSame('2026-10-26T09:00:00+01:00', EncodingReminderService::dueAt($m, EncodingReminderStage::D2)->format('c'));
        self::assertSame('2026-10-28T09:00:00+01:00', EncodingReminderService::dueAt($m, EncodingReminderStage::D4)->format('c'));
    }

    /** Passage à l'heure d'été (29/03/2026 02:00 → 03:00). */
    public function test_spring_dst_change_keeps_nine_am_local(): void
    {
        $m = $this->mission('2026-03-28 16:00');

        self::assertSame('2026-03-29T09:00:00+02:00', EncodingReminderService::dueAt($m, EncodingReminderStage::D1)->format('c'));
    }

    /** La date de référence est la date LOCALE de fin, jamais la date UTC. */
    public function test_reference_day_is_the_local_end_date_not_utc(): void
    {
        // 00:30 à Bruxelles le 15/07 = 22:30 UTC le 14/07.
        $m = $this->mission('2026-07-15 00:30');
        self::assertSame('2026-07-16T09:00:00+02:00', EncodingReminderService::dueAt($m, EncodingReminderStage::D1)->format('c'));

        // Mission finie à 23:45 : J+1 = le lendemain, jamais le jour même.
        $late = $this->mission('2026-07-14 23:45');
        self::assertSame('2026-07-15T09:00:00+02:00', EncodingReminderService::dueAt($late, EncodingReminderStage::D1)->format('c'));
    }

    /** @return iterable<string, array{string, ?EncodingReminderStage}> */
    public static function windows(): iterable
    {
        yield 'le soir même'          => ['2026-07-14 20:00', null];
        yield 'J+1 08:59'             => ['2026-07-15 08:59', null];
        yield 'J+1 09:00'             => ['2026-07-15 09:00', EncodingReminderStage::D1];
        yield 'J+1 18:00'             => ['2026-07-15 18:00', EncodingReminderStage::D1];
        yield 'J+2 08:59 (rattrapage J+1)' => ['2026-07-16 08:59', EncodingReminderStage::D1];
        yield 'J+2 09:00'             => ['2026-07-16 09:00', EncodingReminderStage::D2];
        yield 'J+3 09:00 (rien)'      => ['2026-07-17 09:00', null];
        yield 'J+4 09:00'             => ['2026-07-18 09:00', EncodingReminderStage::D4];
        yield 'J+5 09:00 (fini)'      => ['2026-07-19 09:00', null];
        yield 'J+12 (jamais de rafale)' => ['2026-07-26 10:00', null];
    }

    #[DataProvider('windows')]
    public function test_due_stage_windows(string $now, ?EncodingReminderStage $expected): void
    {
        self::assertSame($expected, EncodingReminderService::dueStage($this->mission('2026-07-14 17:30'), $this->at($now)));
    }

    /** Scénarios 13-14 : soumise (en attente de validation), validée, annulée → plus d'encodage attendu. */
    public function test_only_open_encodings_are_pending(): void
    {
        foreach ([MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS, MissionStatus::ENCODING_IN_PROGRESS, MissionStatus::DECLARED] as $status) {
            self::assertTrue(EncodingReminderService::isEncodingPending($this->mission('2026-07-14 17:30', $status)), $status->value);
        }
        foreach ([MissionStatus::SUBMITTED, MissionStatus::VALIDATED, MissionStatus::CLOSED, MissionStatus::CANCELLED, MissionStatus::REJECTED, MissionStatus::OPEN, MissionStatus::DRAFT] as $status) {
            self::assertFalse(EncodingReminderService::isEncodingPending($this->mission('2026-07-14 17:30', $status)), $status->value);
        }

        self::assertFalse(EncodingReminderService::isEncodingPending($this->mission('2026-07-14 17:30', withInstrumentist: false)));

        $locked = $this->mission('2026-07-14 17:30', MissionStatus::ENCODING_IN_PROGRESS);
        $locked->setEncodingLockedAt(new \DateTimeImmutable());
        self::assertFalse(EncodingReminderService::isEncodingPending($locked));
    }

    public function test_texts_are_the_validated_ones_and_carry_only_the_date(): void
    {
        $d1 = EncodingReminderService::instrumentistContent(EncodingReminderStage::D1, '14/07/2026');
        self::assertSame('Gardez votre journée en mémoire !', $d1['title']);
        self::assertStringContainsString('Vous pouvez reprendre votre brouillon à tout moment.', $d1['push']);

        $d2 = EncodingReminderService::instrumentistContent(EncodingReminderStage::D2, '14/07/2026');
        self::assertSame('Votre encodage attend sa dernière touche', $d2['title']);
        self::assertStringContainsString('finaliser votre mission du 14/07/2026', $d2['push']);
        self::assertSame('Un petit rappel pour votre mission SurgicalHub', $d2['subject']);

        $d4 = EncodingReminderService::instrumentistContent(EncodingReminderStage::D4, '14/07/2026');
        self::assertSame('Votre mission est toujours à finaliser', $d4['title']);
        self::assertStringContainsString('prestation du 14/07/2026', $d4['push']);
        self::assertStringContainsString('coordination a également été informée', $d4['push']);

        foreach ([$d1, $d2, $d4] as $content) {
            self::assertDoesNotMatchRegularExpression('/patient|retard|oubli|faute/i', $content['push']);
        }
    }
}
