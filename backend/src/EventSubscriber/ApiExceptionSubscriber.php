<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
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

        // 1) Doctrine DB unique constraint -> 409 (ex: mission claim déjà pris)
        if ($e instanceof UniqueConstraintViolationException) {
            $status = 409;
            $code = 'CONFLICT';
            // Message brut, mais on garde un wording stable si besoin
            $message = $e->getMessage() ?: 'Conflict';
        }
        // 2) Exceptions HTTP Symfony
        elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            // Message brut demandé par tes règles
            $message = $e->getMessage() ?: $message;

            $code = match ($status) {
                401 => 'UNAUTHORIZED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'CONFLICT',
                422 => 'VALIDATION_FAILED',
                default => 'HTTP_ERROR',
            };
        }
        // 3) Cas spécifique : validation 422 (on garde le message brut)
        elseif ($e instanceof UnprocessableEntityHttpException) {
            $status = 422;
            $code = 'VALIDATION_FAILED';
            $message = $e->getMessage() ?: 'Validation failed';
        }

        // Log systématique
        $logContext = [
            'exception_class' => $e::class,
            'exception_message' => $e->getMessage(),
            'status' => $status,
            'code' => $code,
            'path' => $event->getRequest()->getPathInfo(),
            'method' => $event->getRequest()->getMethod(),
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

        // Dev uniquement : debug
        if ($this->kernel->getEnvironment() === 'dev') {
            $payload['error']['debug'] = [
                'exceptionClass' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ];
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }
}
