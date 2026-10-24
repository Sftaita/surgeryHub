<?php

namespace App\Security\Google;

use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * D-157 — validation of a Google Sign-In ID token through Google's tokeninfo endpoint
 * (signature checked by Google), then locally: audience = our OAuth client id, Google issuer,
 * not expired, verified email. Without a configured client id, Google login is disabled:
 * an ID token issued to any other application must never open a SurgicalHub session.
 */
class GoogleIdTokenVerifier
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'GOOGLE_OAUTH_CLIENT_ID')]
        private readonly string $clientId,
    ) {}

    public function isEnabled(): bool
    {
        return trim($this->clientId) !== '';
    }

    /**
     * @return string the verified email of the Google account
     *
     * @throws GoogleTokenRejectedException  the token is not acceptable (the client must not retry it)
     * @throws GoogleUnavailableException    Google could not be reached (transient)
     */
    public function verify(string $idToken): string
    {
        if (!$this->isEnabled()) {
            throw new GoogleTokenRejectedException('disabled');
        }

        try {
            $response = $this->http->request('GET', self::TOKENINFO_URL, [
                'query' => ['id_token' => $idToken],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            $info = $status === 200 ? $response->toArray(false) : [];
        } catch (HttpClientException $e) {
            throw new GoogleUnavailableException($e->getMessage(), 0, $e);
        }

        if ($status >= 500) {
            throw new GoogleUnavailableException('tokeninfo '.$status);
        }
        if ($status !== 200) {
            throw new GoogleTokenRejectedException('invalid');
        }

        if (($info['aud'] ?? null) !== trim($this->clientId)) {
            throw new GoogleTokenRejectedException('audience');
        }
        if (!in_array($info['iss'] ?? null, self::ISSUERS, true)) {
            throw new GoogleTokenRejectedException('issuer');
        }
        $exp = $info['exp'] ?? null;
        if (!is_numeric($exp) || (int) $exp <= Clock::get()->now()->getTimestamp()) {
            throw new GoogleTokenRejectedException('expired');
        }
        // tokeninfo answers the string "true"; accept the boolean as well.
        $verified = $info['email_verified'] ?? null;
        if ($verified !== true && $verified !== 'true') {
            throw new GoogleTokenRejectedException('email_not_verified');
        }
        $email = $info['email'] ?? null;
        if (!is_string($email) || $email === '' || !is_string($info['sub'] ?? null)) {
            throw new GoogleTokenRejectedException('missing_fields');
        }

        return $email;
    }
}
