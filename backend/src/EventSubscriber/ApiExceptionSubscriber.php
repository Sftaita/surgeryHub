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

        if ($e instanceof UniqueConstraintViolationException) {
            $status = 409;
            $code = 'CONFLICT';
            $message = $e->getMessage() ?: 'Conflict';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: $message;

            $code = match ($status) {
                401 => 'UNAUTHORIZED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                409 => 'CONFLICT',
                422 => 'VALIDATION_FAILED',
                default => 'HTTP_ERROR',
            };
        } elseif ($e instanceof UnprocessableEntityHttpException) {
            $status = 422;
            $code = 'VALIDATION_FAILED';
            $message = $e->getMessage() ?: 'Validation failed';
        }

        if ($status === 422) {
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
}