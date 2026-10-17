<?php

namespace App\Tests\Unit\Enum;

use App\Enum\NotificationType;
use PHPUnit\Framework\TestCase;

/**
 * Préférences selon le rôle — chaque type déclare les rôles qui le reçoivent réellement
 * (NotificationType::audienceRoles()), seule source de la liste proposée par
 * GET /api/me/notification-preferences. Ne décide jamais de la distribution.
 */
final class NotificationTypeAudienceTest extends TestCase
{
    public function test_every_type_declares_at_least_one_known_role(): void
    {
        $known = [NotificationType::AUDIENCE_SURGEON, NotificationType::AUDIENCE_INSTRUMENTIST, NotificationType::AUDIENCE_MANAGER];
        foreach (NotificationType::cases() as $type) {
            $roles = $type->audienceRoles(); // un cas oublié lève UnhandledMatchError
            self::assertNotSame([], $roles, $type->value);
            self::assertSame([], array_diff($roles, $known), $type->value);
        }
    }

    public function test_recent_types_go_to_the_roles_that_receive_them(): void
    {
        self::assertSame(['ROLE_INSTRUMENTIST'], NotificationType::ENCODING_UPDATED_BY_SURGEON->audienceRoles());
        self::assertSame(['ROLE_MANAGER'], NotificationType::ASSIGNMENT_REFUSED_BY_SURGEON->audienceRoles());
        self::assertSame(['ROLE_MANAGER'], NotificationType::ASSIGNMENT_REVIEW_REQUESTED->audienceRoles());
        self::assertSame(['ROLE_MANAGER'], NotificationType::PLANNING_ALERT->audienceRoles());
        self::assertTrue(NotificationType::ENCODING_CORRECTION_REQUESTED->concernsRoles(['ROLE_SURGEON']), 'D-155 : renvoi au chirurgien');
        self::assertTrue(NotificationType::ENCODING_CORRECTION_REQUESTED->concernsRoles(['ROLE_INSTRUMENTIST']));
        foreach (['ROLE_SURGEON', 'ROLE_INSTRUMENTIST', 'ROLE_MANAGER'] as $role) {
            self::assertTrue(NotificationType::CATALOGUE_REQUEST_RESOLVED->concernsRoles([$role]), "créateur d'une demande : $role");
        }
    }

    public function test_admin_counts_as_manager_and_several_roles_are_a_union(): void
    {
        self::assertTrue(NotificationType::ASSIGNMENT_REFUSED_BY_SURGEON->concernsRoles(['ROLE_ADMIN']));
        self::assertFalse(NotificationType::ASSIGNMENT_REFUSED_BY_SURGEON->concernsRoles(['ROLE_SURGEON']));
        self::assertTrue(NotificationType::ASSIGNMENT_REFUSED_BY_SURGEON->concernsRoles(['ROLE_SURGEON', 'ROLE_MANAGER']));
        self::assertTrue(NotificationType::ENCODING_SUBMITTED->concernsRoles(['ROLE_SURGEON', 'ROLE_MANAGER']));
        self::assertFalse(NotificationType::ENCODING_SUBMITTED->concernsRoles(['ROLE_USER']));
    }
}
