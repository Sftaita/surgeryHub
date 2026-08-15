<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Choix obligatoire et exclusif déterminant le forfait firme d'une prestation (au lieu
 * du forfait unique standard) — jamais de sémantique clinique en dur (pas de "cage"/
 * "implant"/etc. codé), la question et les options sont entièrement définies par le
 * manager. Voir docs/decisions.md.
 *
 * `offering` est `OneToMany` (pas `OneToOne`) pour ne jamais enfermer le modèle dans un
 * seul groupe par prestation — choix V1 délibéré (documenté) : l'API n'expose qu'un seul
 * groupe `active` à la fois par prestation, mais la relation supporte déjà nativement une
 * évolution future à plusieurs groupes sans migration de schéma.
 */
#[ORM\Entity]
#[ORM\Table(name: 'required_choice_group')]
#[ORM\HasLifecycleCallbacks]
class RequiredChoiceGroup
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['offering:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'choiceGroups')]
    #[ORM\JoinColumn(name: 'firm_service_offering_id', nullable: false)]
    private ?FirmServiceOffering $offering = null;

    /** Question métier affichée à l'instrumentiste — texte libre défini par le manager. */
    #[ORM\Column(length: 500)]
    #[Groups(['offering:read'])]
    private ?string $question = null;

    /**
     * Un seul groupe actif par prestation en V1 (appliqué en service, pas en base — voir
     * docblock de la classe). false = prestation revenue au forfait unique ; les données
     * (options, PricingRule liées, sélections historiques sur MissionIntervention)
     * restent intactes, jamais supprimées.
     */
    #[ORM\Column(options: ['default' => true])]
    #[Groups(['offering:read'])]
    private bool $active = true;

    /** @var Collection<int, ChoiceOption> */
    #[ORM\OneToMany(mappedBy: 'group', targetEntity: ChoiceOption::class, orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC'])]
    #[Groups(['offering:read'])]
    private Collection $options;

    public function __construct()
    {
        $this->options = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOffering(): ?FirmServiceOffering
    {
        return $this->offering;
    }

    public function setOffering(FirmServiceOffering $offering): static
    {
        $this->offering = $offering;
        return $this;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = trim($question);
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    /** @return Collection<int, ChoiceOption> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    /** Nombre d'options actives — un groupe n'est "opérationnel" (question posée, réponse exigée) qu'à partir de 2 (une seule option ne serait pas un choix). */
    public function activeOptionsCount(): int
    {
        $count = 0;
        foreach ($this->options as $option) {
            if ($option->isActive()) {
                $count++;
            }
        }
        return $count;
    }

    public function isOperational(): bool
    {
        return $this->active && $this->activeOptionsCount() >= 2;
    }
}
