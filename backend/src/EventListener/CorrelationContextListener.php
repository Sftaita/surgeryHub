<?php

namespace App\EventListener;

use App\Activity\CorrelationContext;
use App\Enum\ActivityActorType;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * H1 (D-126) — opens one CorrelationContext operation per main HTTP request and per
 * console command, and closes it afterwards.
 *
 * X-Request-Id: kept only as the informational requestId, and only if it is a short,
 * plain token — the correlationId itself is always generated here (a client value must
 * never be able to collide with or forge another operation's correlation).
 *
 * messenger:consume does not open an operation: CorrelationMiddleware opens one per
 * message instead.
 */
final class CorrelationContextListener
{
    private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    public function __construct(
        private readonly CorrelationContext $context,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $header    = $event->getRequest()->headers->get('X-Request-Id');
        $requestId = ($header !== null && preg_match(self::REQUEST_ID_PATTERN, $header)) ? $header : null;

        $this->context->begin(ActivityActorType::USER, $requestId);
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -4096)]
    public function onTerminate(TerminateEvent $event): void
    {
        $this->context->reset();
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 4096)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        if ($event->getCommand()?->getName() === 'messenger:consume') {
            $this->context->reset();
            return;
        }

        $this->context->begin(ActivityActorType::SCHEDULED_TASK);
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -4096)]
    public function onCommandTerminate(): void
    {
        $this->context->reset();
    }
}
