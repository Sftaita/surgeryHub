<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
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

        if ($e instanceof HttpExceptionInterface) {
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
        }

        if ($e instanceof UnprocessableEntityHttpException) {
            // Le message contient la string des violations Symfony.
            // Option: plus tard, on parse proprement via ValidationFailedException.
            $status = 422;
            $code = 'VALIDATION_FAILED';
            $message = 'Validation failed';
        }

        $event->setResponse(new JsonResponse([
            'error' => [
                'status' => $status,
                'code' => $code,
                'message' => $message,
                'violations' => $violations,
            ],
        ], $status));
    }
}
