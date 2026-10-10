<?php

namespace App\Tests\Unit\Service;

use App\Monitoring\SentryIntegrationSecretScrubber;
use App\Security\MedVueIntegration\MedVueIntegrationAuthenticator;
use App\Service\MedVue\MedVueAccountLinkService;
use App\Service\MedVue\MedVueLinkCodeRedeemer;
use App\Service\MedVue\MedVueLinkException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\Event;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** D-140 — briques unitaires de l'intégration MedVue (sans noyau ni base). */
final class MedVueIntegrationUnitTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function codes(): iterable
    {
        yield 'canonical' => ['K7QM2XPA9DRT', 'K7QM2XPA9DRT'];
        yield 'dashes and lowercase' => ['k7qm-2xpa-9drt', 'K7QM2XPA9DRT'];
        yield 'spaces' => [' K7QM 2XPA 9DRT ', 'K7QM2XPA9DRT'];
        yield 'O I L confusions' => ['O1IL-0000-AAAA', '01110000AAAA'];
        yield 'U is not Crockford' => ['U7QM2XPA9DRT', null];
        yield 'too short' => ['K7QM2XPA9DR', null];
        yield 'too long' => ['K7QM2XPA9DRTX', null];
        yield 'symbols' => ['K7QM2XPA9DR!', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('codes')]
    public function test_code_normalization(string $raw, ?string $expected): void
    {
        self::assertSame($expected, MedVueAccountLinkService::normalizeCode($raw));
    }

    public function test_authenticator_accepts_any_configured_hash_and_ignores_garbage_entries(): void
    {
        $authenticator = new MedVueIntegrationAuthenticator(
            ' not-a-hash ,' . strtoupper(hash('sha256', 'old-secret')) . ',' . hash('sha256', 'new-secret') . ',',
        );

        foreach (['old-secret', 'new-secret'] as $secret) {
            $request = Request::create('/api/integrations/medvue/v1/links/x/absences', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $secret]);
            self::assertSame('medvue-integration', $authenticator->authenticate($request)->getUser()->getUserIdentifier());
        }

        foreach (['Bearer not-a-hash', 'Bearer ', 'Basic b2xkLXNlY3JldA==', ''] as $header) {
            $request = Request::create('/x', server: $header === '' ? [] : ['HTTP_AUTHORIZATION' => $header]);
            try {
                $authenticator->authenticate($request);
                self::fail('Expected rejection for ' . $header);
            } catch (AuthenticationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_authenticator_with_empty_configuration_rejects_everything(): void
    {
        $authenticator = new MedVueIntegrationAuthenticator('');
        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate(Request::create('/x', server: ['HTTP_AUTHORIZATION' => 'Bearer ']));
    }

    public function test_sentry_scrubber_removes_body_query_and_headers_of_integration_requests_only(): void
    {
        $scrubber = new SentryIntegrationSecretScrubber();

        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://api.surgicalhub.be/api/medvue-integration/users/12/link',
            'method' => 'POST',
            'data' => ['code' => 'K7QM2XPA9DRT'],
            'headers' => ['Authorization' => 'Bearer jwt'],
            'query_string' => 'a=b',
        ]);
        $request = $scrubber($event)->getRequest();
        self::assertSame(['url' => 'https://api.surgicalhub.be/api/medvue-integration/users/12/link', 'method' => 'POST'], $request);

        $machine = Event::createEvent();
        $machine->setRequest(['url' => 'https://api.surgicalhub.be/api/integrations/medvue/v1/links/x/absences', 'headers' => ['Authorization' => 'Bearer secret']]);
        self::assertArrayNotHasKey('headers', $scrubber($machine)->getRequest());

        $other = Event::createEvent();
        $other->setRequest(['url' => 'https://api.surgicalhub.be/api/missions/1', 'data' => ['x' => 1]]);
        self::assertSame(['x' => 1], $scrubber($other)->getRequest()['data']);
    }

    public function test_redeemer_without_configuration_never_calls_out(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \LogicException('No request expected.');
        });
        $redeemer = new MedVueLinkCodeRedeemer($client, new NullLogger(), '', '');

        self::assertFalse($redeemer->isConfigured());
        try {
            $redeemer->redeem('K7QM2XPA9DRT', '1', 'a', 'b', false);
            self::fail('Expected not configured.');
        } catch (MedVueLinkException $e) {
            self::assertSame(MedVueLinkException::NOT_CONFIGURED, $e->reason);
            self::assertSame(503, $e->httpStatus());
        }
    }

    public function test_link_exception_messages_never_echo_input(): void
    {
        foreach ([
            MedVueLinkException::INVALID_CODE, MedVueLinkException::INVALID_CODE_FORMAT, MedVueLinkException::UNAVAILABLE,
            MedVueLinkException::RATE_LIMITED, MedVueLinkException::SURGICALHUB_ACCOUNT_TAKEN, MedVueLinkException::MEDVUE_ACCOUNT_TAKEN,
        ] as $reason) {
            $message = (new MedVueLinkException($reason))->getMessage();
            self::assertNotSame('', $message);
            self::assertStringNotContainsString('%', $message);
        }
    }
}
