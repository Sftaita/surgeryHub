<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
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

    /** Socle mobile chirurgien Lot 2 (D-095) — /app/s/missions/{id} existe réellement. */
    public function test_mission_tied_notification_routes_to_surgeon_mission_detail(): void
    {
        $mission = $this->makeMission();
        $surgeon = $this->makeUser('ROLE_SURGEON');

        $url = $this->resolver->resolve(NotificationType::SURGEON_POST_COVERED, $mission, $surgeon);
        self::assertSame('/app/s/missions/' . $mission->getId(), $url);
    }

    // ── Correctif workflow Demandes Catalogue — deep-link (kind, requestId) ──

    public function test_catalogue_request_created_routes_manager_to_the_generic_list_without_requestId(): void
    {
        $mission = $this->makeMission();
        $manager = $this->makeUser('ROLE_MANAGER');

        $url = $this->resolver->resolve(NotificationType::CATALOGUE_REQUEST_CREATED, $mission, $manager);
        self::assertSame('/app/m/catalogue/requests', $url);
    }

    /**
     * Le couple (kind, requestId) est obligatoire pour le deep-link, jamais requestId
     * seul : MaterialItemRequest et InterventionTypeRequest ont des espaces d'ID
     * indépendants et peuvent partager le même id.
     */
    public function test_catalogue_request_created_deep_links_with_kind_and_request_id(): void
    {
        $mission = $this->makeMission();
        $manager = $this->makeUser('ROLE_MANAGER');

        $url = $this->resolver->resolve(NotificationType::CATALOGUE_REQUEST_CREATED, $mission, $manager, 42, CatalogueRequestKind::MATERIAL_ITEM);
        self::assertSame('/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=42', $url);

        $url2 = $this->resolver->resolve(NotificationType::CATALOGUE_REQUEST_CREATED, $mission, $manager, 42, CatalogueRequestKind::INTERVENTION_TYPE);
        self::assertSame('/app/m/catalogue/requests?kind=INTERVENTION_TYPE&requestId=42', $url2);
        self::assertNotSame($url, $url2, 'same numeric id, different kind — must not collide');
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

    // ── Socle mobile chirurgien Lot 2 (D-095) ───────────────────────────────

    public function test_planning_deployed_surgeon_routes_to_their_planning(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $url = $this->resolver->resolve(NotificationType::PLANNING_DEPLOYED_SURGEON, null, $surgeon);
        self::assertSame('/app/s/planning', $url);
    }

    public function test_planning_resent_manual_routes_surgeon_to_their_planning(): void
    {
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $url = $this->resolver->resolve(NotificationType::PLANNING_RESENT_MANUAL, null, $surgeon);
        self::assertSame('/app/s/planning', $url);
    }

    public function test_planning_resent_manual_still_routes_manager_and_instrumentist_unchanged(): void
    {
        $manager = $this->makeUser('ROLE_MANAGER');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');

        self::assertSame(
            '/app/m/missions',
            $this->resolver->resolve(NotificationType::PLANNING_RESENT_MANUAL, null, $manager),
        );
        self::assertSame(
            '/app/i/planning',
            $this->resolver->resolve(NotificationType::PLANNING_RESENT_MANUAL, null, $instr),
        );
    }

    /** Un chirurgien ne doit jamais recevoir le lien mission d'un autre rôle, et inversement. */
    public function test_mission_tied_notification_target_is_role_specific_not_shared_across_roles(): void
    {
        $mission = $this->makeMission();
        $manager = $this->makeUser('ROLE_MANAGER');
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $surgeon = $this->makeUser('ROLE_SURGEON');

        $managerUrl = $this->resolver->resolve(NotificationType::PLANNING_MISSION_REASSIGNED, $mission, $manager);
        $instrUrl = $this->resolver->resolve(NotificationType::PLANNING_MISSION_REASSIGNED, $mission, $instr);
        $surgeonUrl = $this->resolver->resolve(NotificationType::PLANNING_MISSION_REASSIGNED, $mission, $surgeon);

        self::assertSame('/app/m/missions/' . $mission->getId(), $managerUrl);
        self::assertSame('/app/i/missions/' . $mission->getId(), $instrUrl);
        self::assertSame('/app/s/missions/' . $mission->getId(), $surgeonUrl);
        self::assertNotSame($managerUrl, $surgeonUrl);
        self::assertNotSame($instrUrl, $surgeonUrl);
    }
}
