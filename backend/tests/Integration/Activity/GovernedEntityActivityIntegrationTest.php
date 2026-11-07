<?php

namespace App\Tests\Integration\Activity;

use App\Activity\CorrelationContext;
use App\Entity\Hospital;
use App\Entity\RecurrenceRule;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\ActivityActorType;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * H5b (D-132) — the governed-entity journal against the real database: one event per entity
 * and flush, in the flush transaction, with the diff of the tracked fields only, a readable
 * sentence, the acting manager, and nothing for a change of untracked fields.
 */
final class GovernedEntityActivityIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var array<string, list<int>> */
    private array $created = ['users' => [], 'sites' => [], 'posts' => []];
    private User $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(CorrelationContext::class)->begin(ActivityActorType::USER);
        $this->manager = $this->user('Samy', 'Ftaita', ['ROLE_MANAGER']);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->manager, 'main', $this->manager->getRoles()));
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();
            $c = $this->em->getConnection();
            foreach ($this->created['posts'] as $id) {
                $c->executeStatement('DELETE FROM surgeon_schedule_post WHERE id = ?', [$id]);
            }
            foreach ($this->created['sites'] as $id) {
                $c->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
            }
            foreach ($this->created['users'] as $id) {
                $c->executeStatement('DELETE FROM user WHERE id = ?', [$id]);
            }
        }
        parent::tearDown();
    }

    public function test_a_habitual_post_tells_its_creation_its_new_instrumentist_and_its_deactivation(): void
    {
        $schiepers = $this->user('Philippe', 'Schiepers', ['ROLE_SURGEON']);
        $francoise = $this->user('Françoise', 'Detrembleur', ['ROLE_INSTRUMENTIST']);
        $sophie    = $this->user('Sophie', 'Gillard', ['ROLE_INSTRUMENTIST']);
        $site      = $this->site('CHU Delta');

        $post = (new SurgeonSchedulePost())->setSurgeon($schiepers)->setSite($site)->setType(MissionType::BLOCK)
            ->setPeriod(ShiftPeriod::MATIN)->setRecurrence((new RecurrenceRule())->setFrequency(RecurrenceFrequency::WEEKLY)->setInterval(1)->setWeekdays([5])->setAnchorDate(new \DateTimeImmutable('2027-01-01')))
            ->setInstrumentist($francoise)->setStartDate(new \DateTimeImmutable('2027-01-01'))->setCreatedBy($this->manager);
        $this->em->persist($post);
        $this->em->flush();
        $this->created['posts'][] = $post->getId();

        $post->setInstrumentist($sophie);
        $this->em->flush();
        $this->em->flush(); // nothing changed: nothing written
        $post->setActive(false);
        $this->em->flush();

        $events = $this->events('SurgeonSchedulePost', $post->getId());
        self::assertSame(['SURGEON_SCHEDULE_POST_CREATED', 'SURGEON_SCHEDULE_POST_UPDATED', 'SURGEON_SCHEDULE_POST_DEACTIVATED'], array_column($events, 'event_type'));
        self::assertSame('Samy Ftaita a modifié le poste habituel du Dr Philippe Schiepers du vendredi : Françoise Detrembleur → Sophie Gillard', $events[1]['summary']);
        $payload = json_decode($events[1]['payload'], true);
        self::assertEquals([['field' => 'instrumentist', 'from' => 'Françoise Detrembleur', 'to' => 'Sophie Gillard', 'fromId' => $francoise->getId(), 'toId' => $sophie->getId(), 'redacted' => false]], $payload['changes']);
        self::assertSame('PLANNING', $events[1]['domain']);
        self::assertSame((string) $this->manager->getId(), (string) $events[1]['actor_user_id']);
        self::assertSame((string) $site->getId(), (string) $events[1]['site_id']);
        self::assertStringContainsString('a désactivé le poste habituel du Dr Philippe Schiepers du vendredi', $events[2]['summary']);
    }

    /** @return list<array<string, mixed>> */
    private function events(string $subjectType, int $subjectId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM activity_event WHERE subject_type = ? AND subject_id = ? ORDER BY id', [$subjectType, $subjectId]);
    }

    private function user(string $first, string $last, array $roles): User
    {
        $u = (new User())->setEmail('h5b-' . bin2hex(random_bytes(5)) . '@surgicalhub.test')->setFirstname($first)->setLastname($last)
            ->setRoles($roles)->setActive(true)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();

        return $u;
    }

    private function site(string $name): Hospital
    {
        $h = (new Hospital())->setName($name . ' ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->created['sites'][] = $h->getId();

        return $h;
    }
}
