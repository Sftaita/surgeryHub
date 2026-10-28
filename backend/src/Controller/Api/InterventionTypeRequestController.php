<?php

namespace App\Controller\Api;

use App\Dto\Request\InterventionTypeRequestCreateRequest;
use App\Dto\Request\Response\FirmSlimDto;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Exception\CatalogueRequestException;
use App\Message\CatalogueRequestCreatedMessage;
use App\Service\CatalogueFactory;
use App\Security\Voter\MissionVoter;
use App\Service\ActiveFirmResolver;
use App\Service\MissionEncodingGuard;
use App\Service\MissionEncodingLock;
use App\Service\MissionInterventionDraftService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Lot 5 (D-068) — "demande de nouveau type", miroir de MaterialItemRequestController.
 * Aucune MissionIntervention n'est créée ici : c'est le manager qui, en résolvant la
 * demande (InterventionTypeRequestManagerController::resolve), crée l'intervention
 * réelle avec le type choisi/créé.
 *
 * EPIC Revue instrumentiste, Lot 3 — create() ne construit plus qu'un
 * InterventionTypeRequest en mémoire (jamais persisté séparément) puis délègue
 * l'intégralité de la création atomique (demande + MissionInterventionDraft, un seul
 * flush transactionnel, audit inclus) à MissionInterventionDraftService::
 * createForRequest() — remplace l'ancien InterventionTypeRequestService (deux flush()
 * indépendants, aucun draft, supprimé dans ce lot).
 */
#[Route('/api/missions/{missionId}/intervention-type-requests')]
class InterventionTypeRequestController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionInterventionDraftService $draftService,
        private readonly ActiveFirmResolver $firmResolver,
        private readonly MissionEncodingGuard $encodingGuard,
        private readonly SerializerInterface $serializer,
        private readonly MessageBusInterface $bus,
        private readonly ValidatorInterface $validator,
        // D-171 — every encoding write runs under the mission lock (MissionEncodingLock).
        private readonly MissionEncodingLock $encodingLock,
    ) {}

    #[Route('', methods: ['POST'])]
    public function create(int $missionId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $mission = $this->em->find(Mission::class, $missionId);
        if (!$mission) {
            return $this->json(['message' => 'Mission not found'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(MissionVoter::EDIT_ENCODING, $mission);
        $this->encodingGuard->assertEncodingAllowed($mission, $user);

        /** @var InterventionTypeRequestCreateRequest $dto */
        $dto = $this->serializer->deserialize(
            $request->getContent(),
            InterventionTypeRequestCreateRequest::class,
            'json'
        );

        // D-146 bis — contraintes du DTO réellement appliquées (jusqu'ici jamais validé :
        // un libellé vide était accepté par l'API).
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            throw new UnprocessableEntityHttpException((string) $errors);
        }

        if (CatalogueFactory::normalizeName($dto->label) === '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::LABEL_REQUIRED, "Le nom de l'intervention est obligatoire.");
        }

        // D-146 bis — « Avec quelle firme cette intervention a-t-elle été réalisée ? » :
        // obligatoire, exactement une firme existante OU un nom libre proposé.
        $proposedFirmName = CatalogueFactory::normalizeName($dto->proposedFirmName);
        if ($dto->requestedFirmId !== null && $proposedFirmName !== '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_AMBIGUOUS, 'Choisissez une firme existante OU proposez une nouvelle firme, pas les deux.');
        }
        if ($dto->requestedFirmId === null && $proposedFirmName === '') {
            throw new CatalogueRequestException(422, CatalogueRequestException::FIRM_REQUIRED, 'Avec quelle firme cette intervention a-t-elle été réalisée ? Choisissez une firme ou indiquez « Ma firme n\'est pas dans la liste ».');
        }

        // Résolue AVANT la transaction de MissionInterventionDraftService::createForRequest() :
        // simple lecture, rien n'est encore persisté si la firme est invalide.
        $requestedFirm = $dto->requestedFirmId !== null
            ? $this->firmResolver->resolveActive($dto->requestedFirmId)
            : null;

        $req = new InterventionTypeRequest();
        $req
            ->setMission($mission)
            ->setLabel(CatalogueFactory::normalizeName($dto->label))
            ->setSuggestedCode(CatalogueFactory::normalizeName($dto->suggestedCode) ?: null)
            ->setComment($dto->comment)
            ->setCreatedBy($user);

        $draft = $this->encodingLock->run($mission, fn () => $this->draftService->createForRequest($req, $requestedFirm, $user, $requestedFirm === null ? $proposedFirmName : null));

        // Follow-up D-093 — prévient les managers/admins (jamais bloquant, voir handler
        // async). Toujours après le flush transactionnel ci-dessus : la demande doit
        // exister avant qu'on annonce sa création.
        $this->bus->dispatch(new CatalogueRequestCreatedMessage(
            kind: CatalogueRequestKind::INTERVENTION_TYPE,
            requestId: $req->getId(),
            missionId: $mission->getId(),
            label: $req->getLabel(),
            occurredAt: new \DateTimeImmutable(),
        ));

        // EPIC Revue instrumentiste, Lot 3, commit 8 — enrichissement additif de la
        // réponse ({id} seul jusqu'ici) : le frontend a besoin de draftId/orderIndex/
        // label/requestedFirm pour normaliser son cache React Query sans un second
        // aller-retour réseau après la création (insérer l'entrée DRAFT optimiste
        // définitive à la place du placeholder temporaire). Toujours pas de DTO de
        // lecture complet du draft ici (hors périmètre) — seulement les champs
        // strictement nécessaires à cette normalisation.
        return $this->json([
            'id' => $req->getId(),
            'draftId' => $draft->getId(),
            'orderIndex' => $draft->getOrderIndex(),
            'label' => $draft->getLabel(),
            'requestedFirm' => $requestedFirm ? new FirmSlimDto(
                id: (int) $requestedFirm->getId(),
                name: (string) $requestedFirm->getName(),
            ) : null,
            'proposedFirmName' => $draft->getProposedFirmName(),
        ], Response::HTTP_CREATED);
    }
}
