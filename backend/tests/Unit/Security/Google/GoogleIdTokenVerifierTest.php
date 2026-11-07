<?php

namespace App\Tests\Unit\Security\Google;

use App\Security\Google\GoogleIdTokenVerifier;
use App\Security\Google\GoogleTokenRejectedException;
use App\Security\Google\GoogleUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** D-157 — Google ID token checks (audience, issuer, expiry, verified email); disabled without a client id. */
final class GoogleIdTokenVerifierTest extends TestCase
{
    private const CLIENT = 'client-1.apps.googleusercontent.com';

    public function testValidTokenGivesTheVerifiedEmail(): void
    {
        self::assertSame('a@b.test', $this->verifier($this->claims())->verify('id-token'));
        self::assertSame('a@b.test', $this->verifier($this->claims(['iss' => 'accounts.google.com', 'email_verified' => true]))->verify('id-token'));
    }

    public function testDisabledWithoutClientIdAndNoCallToGoogle(): void
    {
        $http = new MockHttpClient(static fn () => throw new \LogicException('Google must not be called.'));
        $verifier = new GoogleIdTokenVerifier($http, ' ');

        self::assertFalse($verifier->isEnabled());
        $this->expectException(GoogleTokenRejectedException::class);
        $verifier->verify('id-token');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rejectedClaims(): iterable
    {
        yield 'other application' => [['aud' => 'other.apps.googleusercontent.com'], 'audience'];
        yield 'no audience' => [['aud' => null], 'audience'];
        yield 'foreign issuer' => [['iss' => 'https://evil.example'], 'issuer'];
        yield 'expired' => [['exp' => (string) (time() - 1)], 'expired'];
        yield 'no expiry' => [['exp' => null], 'expired'];
        yield 'unverified email' => [['email_verified' => 'false'], 'email_not_verified'];
        yield 'no email_verified' => [['email_verified' => null], 'email_not_verified'];
        yield 'no email' => [['email' => null], 'missing_fields'];
        yield 'no subject' => [['sub' => null], 'missing_fields'];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedClaims')]
    public function testRejectedClaims(array $override, string $reason): void
    {
        $this->expectException(GoogleTokenRejectedException::class);
        $this->expectExceptionMessage($reason);
        $this->verifier($this->claims($override))->verify('id-token');
    }

    public function testGoogleRefusalIsARejectionAndGoogleOutageIsTransient(): void
    {
        $refused = new GoogleIdTokenVerifier(new MockHttpClient(new MockResponse('{"error":"invalid_token"}', ['http_code' => 400])), self::CLIENT);
        try {
            $refused->verify('id-token');
            self::fail('expected a rejection');
        } catch (GoogleTokenRejectedException $e) {
            self::assertSame('invalid', $e->getMessage());
        }

        $down = new GoogleIdTokenVerifier(new MockHttpClient(new MockResponse('', ['http_code' => 502])), self::CLIENT);
        try {
            $down->verify('id-token');
            self::fail('expected unavailability');
        } catch (GoogleUnavailableException) {
        }

        $unreachable = new GoogleIdTokenVerifier(new MockHttpClient(static fn () => throw new TransportException('timeout')), self::CLIENT);
        $this->expectException(GoogleUnavailableException::class);
        $unreachable->verify('id-token');
    }

    /**
     * @param array<string, mixed> $override null removes the claim
     *
     * @return array<string, mixed>
     */
    private function claims(array $override = []): array
    {
        $claims = array_merge([
            'aud' => self::CLIENT, 'iss' => 'https://accounts.google.com', 'exp' => (string) (time() + 600),
            'email' => 'a@b.test', 'email_verified' => 'true', 'sub' => '1234',
        ], $override);

        return array_filter($claims, static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $claims */
    private function verifier(array $claims): GoogleIdTokenVerifier
    {
        return new GoogleIdTokenVerifier(new MockHttpClient(new MockResponse((string) json_encode($claims), ['http_code' => 200])), self::CLIENT);
    }
}
