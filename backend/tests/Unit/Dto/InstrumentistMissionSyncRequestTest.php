<?php

namespace App\Tests\Unit\Dto;

use App\Dto\Request\InstrumentistMissionSyncRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * GET /api/instrumentist/missions/sync?since=...
 *
 * - since absent => premier sync, pas d'erreur
 * - since ISO 8601 valide => pas d'erreur
 * - since invalide => violation (-> 422 via le controller)
 */
class InstrumentistMissionSyncRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testSinceAbsentIsValid(): void
    {
        $dto = InstrumentistMissionSyncRequest::fromQuery([]);

        $this->assertFalse($dto->sinceProvided);
        $this->assertCount(0, $this->validator->validate($dto));
    }

    public function testSinceValidIso8601IsValid(): void
    {
        $dto = InstrumentistMissionSyncRequest::fromQuery(['since' => '2026-06-01T00:00:00+00:00']);

        $this->assertTrue($dto->sinceProvided);
        $this->assertNotNull($dto->sinceParsed);
        $this->assertCount(0, $this->validator->validate($dto));
    }

    public function testSinceInvalidProducesViolation(): void
    {
        $dto = InstrumentistMissionSyncRequest::fromQuery(['since' => 'not-a-date']);

        $this->assertTrue($dto->sinceProvided);
        $this->assertNull($dto->sinceParsed);

        $violations = $this->validator->validate($dto);
        $this->assertGreaterThan(0, count($violations));
        $this->assertSame('since', $violations[0]->getPropertyPath());
    }
}
