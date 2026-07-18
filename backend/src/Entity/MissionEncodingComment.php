<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lot 7 (D-070) — commentaire métier laissé par le manager lors d'un refus ou d'une
 * réouverture d'encodage (matériel manquant, quantité incorrecte, mauvaise firme,
 * intervention incomplète, etc.). Historisé et rattaché à la mission — jamais perdu,
 * jamais écrasé par un commentaire suivant : chaque reject/reopen avec commentaire crée
 * une nouvelle ligne, jamais une mise à jour d'un champ unique.
 *
 * Distinct de l'AuditEvent de la même transition : l'AuditEvent trace QUE la transition a
 * eu lieu (fait technique, payload générique) ; ce commentaire porte le CONTENU métier
 * (pourquoi), pensé pour être lu et parcouru par un manager, pas seulement audité.
 */
#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_mission_encoding_comment_mission', columns: ['mission_id'])])]
#[ORM\HasLifecycleCallbacks]
class MissionEncodingComment
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'encodingComments')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?User $author = null;

    #[ORM\Column(type: 'text')]
    #[Groups(['mission:read_manager'])]
    private ?string $comment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMission(): ?Mission
    {
        return $this->mission;
    }

    public function setMission(Mission $mission): static
    {
        $this->mission = $mission;
        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(User $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }
}
