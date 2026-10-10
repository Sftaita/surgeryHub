<?php

namespace App\Service\MedVue;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * D-140 — seul appel SurgicalHub → MedVue : échange d'un code d'association contre un
 * identifiant de liaison (contrat v1, docs/api.md « Intégration MedVue »).
 *
 * POST {MEDVUE_API_BASE_URL}/api/integrations/surgicalhub/v1/link-codes/redeem, corps plat
 * {code, surgicalHubUserId, surgicalHubDisplayName, actorDisplayName, actorIsAdministrator}.
 * Ne transporte que des données SurgicalHub vers MedVue ; la réponse ne contient que
 * {linkId, linkedAt}. Toute réponse hors contrat (statut, corps, délai de 10 s, redirection)
 * est un échec technique : rien n'est enregistré côté SurgicalHub.
 *
 * Le code n'est jamais journalisé : les logs ne portent que le statut HTTP et la raison.
 */
class MedVueLinkCodeRedeemer
{
    private const PATH = '/api/integrations/surgicalhub/v1/link-codes/redeem';
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MEDVUE_API_BASE_URL)%')] private readonly string $baseUrl,
        #[Autowire('%env(MEDVUE_REDEEM_TOKEN)%')] private readonly string $token,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '' && trim($this->token) !== '';
    }

    /**
     * @return array{linkId: string, linkedAt: \DateTimeImmutable}
     *
     * @throws MedVueLinkException
     */
    public function redeem(
        string $normalizedCode,
        string $surgicalHubUserId,
        string $surgicalHubDisplayName,
        string $actorDisplayName,
        bool $actorIsAdministrator,
    ): array {
        if (!$this->isConfigured()) {
            throw new MedVueLinkException(MedVueLinkException::NOT_CONFIGURED);
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/') . self::PATH, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'],
                'json' => [
                    'code' => $normalizedCode,
                    'surgicalHubUserId' => $surgicalHubUserId,
                    'surgicalHubDisplayName' => mb_substr($surgicalHubDisplayName, 0, 200),
                    'actorDisplayName' => mb_substr($actorDisplayName, 0, 200),
                    'actorIsAdministrator' => $actorIsAdministrator,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body = json_decode($response->getContent(false), true);
        } catch (HttpClientException) {
            $this->logger->warning('MedVue redeem: transport failure', ['reason' => MedVueLinkException::UNAVAILABLE]);
            throw new MedVueLinkException(MedVueLinkException::UNAVAILABLE);
        }

        $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;

        if ($status === 200) {
            return $this->parseSuccess($body);
        }
        if ($status === 422 && $error === 'invalid_code') {
            throw new MedVueLinkException(MedVueLinkException::INVALID_CODE);
        }
        if ($status === 409 && $error === 'already_linked') {
            throw new MedVueLinkException(MedVueLinkException::SURGICALHUB_ACCOUNT_TAKEN);
        }
        if ($status === 409 && $error === 'medvue_account_already_linked') {
            throw new MedVueLinkException(MedVueLinkException::MEDVUE_ACCOUNT_TAKEN);
        }

        // 401 (secret), 422 validation_failed, 429, 5xx, corps inattendu : problème technique,
        // jamais présenté comme un code invalide à l'utilisateur.
        $this->logger->error('MedVue redeem: unexpected response', ['status' => $status, 'error' => $error]);
        throw new MedVueLinkException(MedVueLinkException::UNAVAILABLE);
    }

    /** @return array{linkId: string, linkedAt: \DateTimeImmutable} */
    private function parseSuccess(mixed $body): array
    {
        $linkId = is_array($body) ? ($body['linkId'] ?? null) : null;
        $linkedAt = is_array($body) ? ($body['linkedAt'] ?? null) : null;

        if (!is_string($linkId) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $linkId) !== 1 || !is_string($linkedAt)) {
            $this->logger->error('MedVue redeem: malformed success body', ['status' => 200]);
            throw new MedVueLinkException(MedVueLinkException::UNAVAILABLE);
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $linkedAt);
        if ($parsed === false) {
            $this->logger->error('MedVue redeem: malformed linkedAt', ['status' => 200]);
            throw new MedVueLinkException(MedVueLinkException::UNAVAILABLE);
        }

        return ['linkId' => $linkId, 'linkedAt' => $parsed];
    }
}
