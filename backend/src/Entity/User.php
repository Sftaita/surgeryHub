<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\EmploymentType;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read', 'mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read', 'export:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Groups(['user:read', 'mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read', 'export:read'])]
    private ?string $email = null;

    #[ORM\Column]
    #[Groups(['user:read', 'mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read', 'export:read'])]
    private array $roles = [];

    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read', 'export:read'])]
    private ?string $firstname = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read', 'export:read'])]
    private ?string $lastname = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $companyName = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['user:read', 'mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read'])]
    private bool $active = true;

    #[ORM\Column(enumType: EmploymentType::class, nullable: true)]
    #[Groups(['user:read', 'mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read'])]
    private ?EmploymentType $employmentType = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['mission:read_manager', 'service:read_manager'])]
    private ?string $hourlyRate = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['mission:read_manager', 'service:read_manager'])]
    private ?string $consultationFee = null;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager'])]
    private ?string $defaultCurrency = 'EUR';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $invitationToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invitationExpiresAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $profilePicturePath = null;

    /**
     * @var Collection<int, SiteMembership>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: SiteMembership::class, orphanRemoval: true)]
    private Collection $siteMemberships;

    public function __construct()
    {
        $this->siteMemberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', (string) $this->password);
        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void {}

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): static
    {
        $this->firstname = $firstname;
        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): static
    {
        $this->lastname = $lastname;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = $companyName;
        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): static
    {
        $this->vatNumber = $vatNumber;
        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;
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

    public function getEmploymentType(): ?EmploymentType
    {
        return $this->employmentType;
    }

    public function setEmploymentType(?EmploymentType $employmentType): static
    {
        $this->employmentType = $employmentType;
        return $this;
    }

    public function getHourlyRate(): ?string
    {
        return $this->hourlyRate;
    }

    public function setHourlyRate(?string $hourlyRate): static
    {
        $this->hourlyRate = $hourlyRate;
        return $this;
    }

    public function getConsultationFee(): ?string
    {
        return $this->consultationFee;
    }

    public function setConsultationFee(?string $consultationFee): static
    {
        $this->consultationFee = $consultationFee;
        return $this;
    }

    public function getDefaultCurrency(): ?string
    {
        return $this->defaultCurrency;
    }

    public function setDefaultCurrency(string $defaultCurrency): static
    {
        $this->defaultCurrency = $defaultCurrency;
        return $this;
    }

    public function getInvitationToken(): ?string
    {
        return $this->invitationToken;
    }

    public function setInvitationToken(?string $invitationToken): static
    {
        $this->invitationToken = $invitationToken;
        return $this;
    }

    public function getInvitationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->invitationExpiresAt;
    }

    public function setInvitationExpiresAt(?\DateTimeImmutable $invitationExpiresAt): static
    {
        $this->invitationExpiresAt = $invitationExpiresAt;
        return $this;
    }

    public function getProfilePicturePath(): ?string
    {
        return $this->profilePicturePath;
    }

    public function setProfilePicturePath(?string $profilePicturePath): static
    {
        $this->profilePicturePath = $profilePicturePath;
        return $this;
    }

    public function getSiteMemberships(): Collection
    {
        return $this->siteMemberships;
    }

    public function addSiteMembership(SiteMembership $siteMembership): static
    {
        if (!$this->siteMemberships->contains($siteMembership)) {
            $this->siteMemberships->add($siteMembership);
            $siteMembership->setUser($this);
        }
        return $this;
    }

    public function removeSiteMembership(SiteMembership $siteMembership): static
    {
        if ($this->siteMemberships->removeElement($siteMembership)) {
            if ($siteMembership->getUser() === $this) {
                $siteMembership->setUser(null);
            }
        }
        return $this;
    }
}