<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Service\NotificationTargetResolver;
use PHPUnit\Framework\TestCase;

/**
 * Point 4 (audit UX) — cliquer sur une notification manager/instrumentiste n'ouvrait
 * rien : NotificationEvent n'a jamais qu'une Mission optionnelle + un payload libre, et
 * pour les types agrégés (déploiement, pool OPEN, alerte) mission est toujours null. Ce
 * resolver calcule la cible réelle côté serveur — jamais reconstruite côté frontend.
 */
final class NotificationTargetResolverTest extends TestCase
{
    private NotificationTargetResolver $resolver;
    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->resolver = new NotificationTargetResolver();
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('target-' . self::$nextId . '@test.com');
        $u->setRoles([$role]);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(): Mission
    {
        $m = new Mission();
        $this->setId($m, self::$nextId++);
        return $m;
    }

    // ── Notification rattachée à une Mission ────────────────────────────────

    public function test_mission_tied_notification_routes_to_manager_mission_detail(): void
    {
        $mission = $this->makeMission();
        $manager = $this->makeUser('ROLE_MANAGER');

        $url = $this->resolver->resolve(NotificationType::PLANNING_MISSION_REASSIGNED, $mission, $manager);
        self::assertSame('/app/m/missions/' . $mission->getId(), $url);
    }

    public function test_mission_tied_notification_routes_to_admin_mission_detail(): void
    {
        $mission = $this->makeMission();
        $admin = $this->makeUser('ROLE_ADMIN');

        $url = $this->resolver->resolve(NotificationType::PLANNING_MISSION_CANCELLED, $mission, $admin);
        self::assertSame('/app/m/missions/' . $mission->getId(), $url);
    }

    public function test_mission_tied_notification_routes_to_instrumentist_mission_detail(): void
    {
        $mission = $this->makeMission();
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');

        $url = $this->resolver->resolve(NotificationType::SURGEON_POST_COVERED, $mission, $instr);
        self::assertSame('/app/i/missions/' . $mission->getId(), $url);
    }

    /** Aucun écran de détail mission chirurgien n'existe encore (voir AppRouter.tsx) — limite documentée. */
    public function test_mission_tied_notification_routes_surgeon_to_their_only_existing_page(): void
    {
        $mission = $this->makeMission();
        $surgeon = $this->makeUser('ROLE_SURGEON');

        $url = $this->resolver->resolve(NotificationType::SURGEON_POST_COVERED, $mission, $surgeon);
        self::assertSame('/app/s', $url);
    }

    // ── Notifications agrégées (aucune Mission unique) ──────────────────────

    public function test_planning_deployed_manager_routes_to_missions_list(): void
    {
        $manager = $this->makeUser('ROLE_MANAGER');
        $url = $this->resolver->resolve(NotificationType::PLANNING_DEPLOYED_MANAGER, null, $manager);
        self::assertSame('/app/m/missions', $url);
    }

    public function test_planning_deployed_instrumentist_routes_to_their_planning(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $url = $this->resolver->resolve(NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST, null, $instr);
        self::assertSame('/app/i/planning', $url);
    }

    public function test_open_mission_available_routes_to_offers(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $url = $this->resolver->resolve(NotificationType::OPEN_MISSION_AVAILABLE, null, $instr);
        self::assertSame('/app/i/offers', $url);
    }

    public function test_planning_alert_routes_manager_to_planning_v2(): void
    {
        $manager = $this->makeUser('ROLE_MANAGER');
        $url = $this->resolver->resolve(NotificationType::PLANNING_ALERT, null, $manager);
        self::assertSame('/app/m/planning/v2', $url);
    }

    public function test_planning_alert_is_null_for_non_manager(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $url = $this->resolver->resolve(NotificationType::PLANNING_ALERT, null, $instr);
        self::assertNull($url);
    }
}
