<?php

namespace App\EventSubscriber;

use App\Exception\InterventionTypeInactiveException;
use App\Exception\InterventionTypeNotFoundException;
use App\Exception\MissionNotDraftException;
use App\Exception\PrimaryFirmInactiveException;
use App\Exception\PrimaryFirmNotFoundException;
use App\Exception\PricingRuleImmutableException;
use App\Exception\PricingRulePeriodOverlapException;
use App\Exception\InstrumentistRateImmutableException;
use App\Exception\InstrumentistRatePeriodOverlapException;
use App\Exception\FinancialCalculationIneligibleException;
use App\Exception\FinancialCalculationAnomaliesException;
use App\Exception\DocumentLineSelectionException;
use App\Exception\DocumentAlreadyIssuedException;
use App\Exception\DocumentCannotReleaseLinesException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KernelInterface $kernel,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        $status = 500;
        $code = 'INTERNAL_ERROR';
        $message = 'Internal server error';
        $violations = [];

        if ($e instanceof UniqueConstraintViolationException) {
            $status = 409;
            $code = 'CONFLICT';
            $message = $e->getMessage() ?: 'Conflict';
        } elseif ($e instanceof MissionNotDraftException) {
            $status = 409;
            $code = 'MISSION_NOT_DRAFT';
            $message = $e->getMessage() ?: 'Mission is not DRAFT';
        } elseif ($e instanceof PricingRulePeriodOverlapException) {
            $status = 409;
            $code = 'PRICING_RULE_PERIOD_OVERLAP';
            $message = $e->getMessage() ?: 'Une règle tarifaire existe déjà pour cette période.';
        } elseif ($e instanceof PricingRuleImmutableException) {
            $status = 409;
            $code = 'PRICING_RULE_IMMUTABLE';
            $message = $e->getMessage() ?: 'Cette règle tarifaire est déjà applicable ou passée — elle ne peut plus être modifiée.';
        } elseif ($e instanceof InstrumentistRatePeriodOverlapException) {
            $status = 409;
            $code = 'INSTRUMENTIST_RATE_PERIOD_OVERLAP';
            $message = $e->getMessage() ?: 'Un tarif existe déjà pour cette période.';
        } elseif ($e instanceof InstrumentistRateImmutableException) {
            $status = 409;
            $code = 'INSTRUMENTIST_RATE_IMMUTABLE';
            $message = $e->getMessage() ?: 'Ce tarif est déjà applicable ou passé — il ne peut plus être modifié.';
        } elseif ($e instanceof FinancialCalculationIneligibleException) {
            $status = 409;
            $code = 'FINANCIAL_CALCULATION_INELIGIBLE';
            $message = $e->getMessage() ?: 'Cette mission ne peut pas être valorisée dans son état actuel.';
        } elseif ($e instanceof FinancialCalculationAnomaliesException) {
            $status = 422;
            $code = 'FINANCIAL_CALCULATION_ANOMALIES';
            $message = $e->getMessage();
            $violations = array_map(static fn ($a) => $a->toArray(), $e->getAnomalies());
        } elseif ($e instanceof DocumentLineSelectionException) {
            $status = 422;
            $code = 'DOCUMENT_LINE_SELECTION_FAILED';
            $message = $e->getMessage();
            $violations = array_map(static fn ($a) => $a->toArray(), $e->getAnomalies());
        } elseif ($e instanceof DocumentAlreadyIssuedException) {
            $status = 409;
            $code = 'DOCUMENT_ALREADY_ISSUED';
            $message = $e->getMessage() ?: 'Ce document a déjà été émis.';
        } elseif ($e instanceof DocumentCannotReleaseLinesException) {
            $status = 409;
            $code = 'DOCUMENT_CANNOT_RELEASE_LINES';
            $message = $e->getMessage() ?: 'Les lignes de ce document ne peuvent pas être libérées.';
        } elseif ($e instanceof InterventionTypeNotFoundException) {
            $status = 404;
            $code = 'INTERVENTION_TYPE_NOT_FOUND';
            $message = $e->getMessage() ?: 'Type d\'intervention introuvable.';
        } elseif ($e instanceof InterventionTypeInactiveException) {
            $status = 422;
            $code = 'INTERVENTION_TYPE_INACTIVE';
            $message = $e->getMessage() ?: 'Ce type d\'intervention est désactivé.';
        } elseif ($e instanceof PrimaryFirmNotFoundException) {
            $status = 404;
            $code = 'PRIMARY_FIRM_NOT_FOUND';
            $message = $e->getMessage() ?: 'Firme introuvable.';
        } elseif ($e instanceof PrimaryFirmInactiveException) {
            $status = 422;
            $code = 'PRIMARY_FIRM_INACTIVE';
            $message = $e->getMessage() ?: 'Cette firme est désactivée.';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: $message;

            $code = match ($status) {
                400 => 'BAD_REQUEST',
                401 => 'UNAUTHORIZED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'CONFLICT',
                422 => 'VALIDATION_FAILED',
                default => 'HTTP_ERROR',
            };
        }

        if ($status === 422 && !$e instanceof FinancialCalculationAnomaliesException && !$e instanceof DocumentLineSelectionException) {
            $violations = $this->extractViolationsFromMessage($message);
            if (count($violations) > 0) {
                $message = 'Validation failed';
            }
        }

        if ($status === 409 && $message === 'Email already exists') {
            $violations = [
                [
                    'field' => 'email',
                    'message' => 'Email already exists',
                ],
            ];
        }

        $request = $event->getRequest();

        $logContext = [
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
            'status' => $status,
            'code' => $code,
            'path' => $this->sanitizePath($request->getPathInfo()),
            'method' => $request->getMethod(),
        ];

        if ($status >= 500) {
            $this->logger->error('API exception', $logContext + ['exception' => $e]);
        } else {
            $this->logger->warning('API exception', $logContext + ['exception' => $e]);
        }

        $payload = [
            'error' => [
                'status' => $status,
                'code' => $code,
                'message' => $message,
                'violations' => $violations,
            ],
        ];

        if ($this->kernel->getEnvironment() === 'dev') {
            $payload['error']['debug'] = [
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ];
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }

    /**
     * @return list<array{field: ?string, message: string}>
     */
    private function extractViolationsFromMessage(string $message): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($message)) ?: [];
        $violations = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([^:]+):\s*(.+)$/', $line, $matches) === 1) {
                $violations[] = [
                    'field' => trim($matches[1]),
                    'message' => trim($matches[2]),
                ];
                continue;
            }

            $violations[] = [
                'field' => null,
                'message' => $line,
            ];
        }

        return $violations;
    }

    private function sanitizePath(string $path): string
    {
        return preg_replace('#^/api/invitations/[^/]+$#', '/api/invitations/[REDACTED]', $path) ?? $path;
    }
}