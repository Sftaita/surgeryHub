<?php

namespace App\Tests\Integration;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Deploy email/PDF policy redesign (D-058) — the surgeon PDF must read like a clean
 * paper planning: Date | Horaire | Type | Instrumentation only. No status column, no
 * raw MissionStatus values (OPEN/ASSIGNED/DRAFT), no UncoveredReason labels. An
 * uncovered mission shows "Instrumentiste non attribuée" — those internal concepts
 * belong to the manager, not the surgeon. "À confirmer" was replaced (UX wording pass,
 * 2026-07) because it wrongly implied the surgeon needed to confirm something.
 */
final class PlanningPdfTemplatesTest extends KernelTestCase
{
    private function twig(): Environment
    {
        self::bootKernel();
        return self::getContainer()->get(Environment::class);
    }

    private function makeUser(string $email, string $firstname): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setFirstname($firstname);
        $u->setLastname('Test');
        return $u;
    }

    private function makeMission(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        MissionType $type,
        MissionStatus $status,
        ?User $instrumentist,
    ): Mission {
        $site = new Hospital();
        $site->setName('Alpha');

        $m = new Mission();
        $m->setStartAt($start);
        $m->setEndAt($end);
        $m->setType($type);
        $m->setStatus($status);
        $m->setSite($site);
        $m->setInstrumentist($instrumentist);
        return $m;
    }

    public function test_surgeon_pdf_shows_new_wording_for_uncovered_mission_no_status_column(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', 'Alice');
        $uncovered = $this->makeMission(
            new \DateTimeImmutable('2026-08-25 08:00:00'),
            new \DateTimeImmutable('2026-08-25 18:00:00'),
            MissionType::BLOCK,
            MissionStatus::OPEN,
            null,
        );

        $html = $this->twig()->render('pdf/planning_surgeon.html.twig', [
            'surgeon'    => $surgeon,
            'missions'   => [$uncovered],
            'periodFrom' => new \DateTimeImmutable('2026-08-01'),
            'periodTo'   => new \DateTimeImmutable('2026-08-31'),
        ]);

        $this->assertStringContainsString('Instrumentiste non attribuée', $html);
        $this->assertStringContainsString('25/08/2026', $html);
        $this->assertStringContainsString('08:00–18:00', $html);
        $this->assertStringContainsString('Bloc', $html);

        // No technical/internal concepts must leak into a surgeon-facing document.
        $this->assertStringNotContainsString('À confirmer', $html, 'Old wording must no longer appear anywhere.');
        $this->assertStringNotContainsString('En attente', $html, 'Forbidden alternative wording per UX spec.');
        $this->assertStringNotContainsString('Disponible', $html, 'Forbidden alternative wording per UX spec.');
        $this->assertStringNotContainsString('Statut', $html, 'Status column must be removed entirely.');
        $this->assertStringNotContainsString('OPEN', $html);
        $this->assertStringNotContainsString('ASSIGNED', $html);
        $this->assertStringNotContainsString('DRAFT', $html);
        $this->assertStringNotContainsString('Recherche en cours', $html);
        $this->assertStringNotContainsString('Laissé ouvert manuellement', $html);
        $this->assertStringNotContainsString('Aucune instrumentiste disponible', $html);
        $this->assertStringNotContainsString('Aucun instrumentiste affilié', $html);
    }

    public function test_surgeon_pdf_shows_instrumentist_name_for_assigned_mission(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', 'Alice');
        $instr   = $this->makeUser('instr@test.com', 'Sophie');
        $instr->setLastname('Martin');

        $assigned = $this->makeMission(
            new \DateTimeImmutable('2026-08-18 08:00:00'),
            new \DateTimeImmutable('2026-08-18 18:00:00'),
            MissionType::BLOCK,
            MissionStatus::ASSIGNED,
            $instr,
        );

        $html = $this->twig()->render('pdf/planning_surgeon.html.twig', [
            'surgeon'    => $surgeon,
            'missions'   => [$assigned],
            'periodFrom' => new \DateTimeImmutable('2026-08-01'),
            'periodTo'   => new \DateTimeImmutable('2026-08-31'),
        ]);

        $this->assertStringContainsString('Sophie Martin', $html);
        $this->assertStringNotContainsString('Instrumentiste non attribuée', $html,
            'A covered mission must not show the unassigned placeholder.'
        );
        $this->assertStringNotContainsString('ASSIGNED', $html);
        $this->assertStringNotContainsString('Statut', $html);
    }

    public function test_surgeon_pdf_no_missions_shows_empty_state(): void
    {
        $html = $this->twig()->render('pdf/planning_surgeon.html.twig', [
            'surgeon'    => $this->makeUser('surgeon@test.com', 'Alice'),
            'missions'   => [],
            'periodFrom' => new \DateTimeImmutable('2026-08-01'),
            'periodTo'   => new \DateTimeImmutable('2026-08-31'),
        ]);

        $this->assertStringContainsString('Aucune mission planifiée', $html);
    }

    /**
     * REGRESSION — global PDF ISO-week grouping (P0 bug, found on a real deployment
     * 2026-07-05): a purely numeric-looking week key (e.g. "36") got silently
     * renumbered by PHP's array_merge() (which Twig's merge filter uses), turning
     * byWeek["36"] into byWeek[0] on the very next merge and breaking every subsequent
     * byWeek[week] lookup with "Twig\Error\RuntimeError: Key ... does not exist". Only
     * the week-grouped global PDF was affected — day keys ("2026-09-01") are never
     * numeric so were never at risk. Needs at least 2 missions in *different* ISO
     * weeks to exercise the second merge that used to crash.
     */
    public function test_global_pdf_renders_across_multiple_iso_weeks_without_error(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', 'Alice');
        $instr   = $this->makeUser('instr@test.com', 'Sophie');
        $instr->setLastname('Martin');

        $week1 = $this->makeMission(
            new \DateTimeImmutable('2026-09-01 08:00:00'),
            new \DateTimeImmutable('2026-09-01 18:00:00'),
            MissionType::BLOCK,
            MissionStatus::OPEN,
            null,
        );
        $week1->setSurgeon($surgeon);

        $week2 = $this->makeMission(
            new \DateTimeImmutable('2026-09-08 08:00:00'),
            new \DateTimeImmutable('2026-09-08 18:00:00'),
            MissionType::BLOCK,
            MissionStatus::ASSIGNED,
            $instr,
        );
        $week2->setSurgeon($surgeon);

        $html = $this->twig()->render('pdf/planning_global.html.twig', [
            'missions'   => [$week1, $week2],
            'periodFrom' => new \DateTimeImmutable('2026-09-01'),
            'periodTo'   => new \DateTimeImmutable('2026-09-30'),
        ]);

        $this->assertStringContainsString('Semaine 36', $html);
        $this->assertStringContainsString('Semaine 37', $html);
        $this->assertStringContainsString('Sophie Martin', $html);
        $this->assertStringContainsString('Ouvert', $html, 'Manager PDF keeps MissionStatus::label() (French).');
        $this->assertStringNotContainsString('>OPEN<', $html);
    }

}
