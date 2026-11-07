<?php

namespace App\Tests\Unit\Messenger;

use App\Activity\CorrelationContext;
use App\Activity\UuidV7;
use App\Enum\ActivityActorType;
use App\Messenger\CorrelationMiddleware;
use App\Messenger\CorrelationStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/** H1 (D-126) */
final class CorrelationMiddlewareTest extends TestCase
{
    private CorrelationContext $ctx;
    /** @var list<array<string, mixed>> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->ctx  = new CorrelationContext();
        $this->seen = [];
    }

    private function bus(?\Closure $handler = null): MessageBus
    {
        $handler ??= function (\stdClass $m): void {
            $this->seen[] = [
                'correlationId' => $this->ctx->correlationId(),
                'messageId'     => $this->ctx->messengerMessageId(),
                'actorType'     => $this->ctx->defaultActorType(),
                'anchor'        => $this->ctx->anchor('absence:58'),
            ];
        };

        return new MessageBus([
            new CorrelationMiddleware($this->ctx),
            new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => [$handler]])),
        ]);
    }

    public function test_dispatch_attaches_the_current_operation(): void
    {
        $this->ctx->begin(ActivityActorType::USER);
        $this->ctx->registerAnchor('absence:58', 3);

        $envelope = $this->bus()->dispatch(new \stdClass());
        $stamp    = $envelope->last(CorrelationStamp::class);

        self::assertNotNull($stamp);
        self::assertSame($this->ctx->correlationId(), $stamp->correlationId);
        self::assertSame(['absence:58' => 3], $stamp->anchors);
    }

    public function test_an_existing_stamp_is_kept_on_redispatch(): void
    {
        $this->ctx->begin(ActivityActorType::USER);
        $original = new CorrelationStamp(UuidV7::generate());

        $envelope = $this->bus()->dispatch(new Envelope(new \stdClass(), [$original]));

        self::assertSame($original->correlationId, $envelope->last(CorrelationStamp::class)->correlationId);
    }

    public function test_worker_adopts_the_stamp_then_restores_the_previous_context(): void
    {
        $this->ctx->begin(ActivityActorType::SCHEDULED_TASK);
        $before = $this->ctx->correlationId();
        $origin = UuidV7::generate();

        $this->bus()->dispatch(new Envelope(new \stdClass(), [
            new ReceivedStamp('async'),
            new TransportMessageIdStamp('123'),
            new CorrelationStamp($origin, ['absence:58' => 9]),
        ]));

        self::assertSame($origin, $this->seen[0]['correlationId']);
        self::assertSame('123', $this->seen[0]['messageId']);
        self::assertSame(ActivityActorType::WORKER, $this->seen[0]['actorType']);
        self::assertSame(9, $this->seen[0]['anchor']);
        self::assertSame($before, $this->ctx->correlationId(), 'previous operation restored');
        self::assertNull($this->ctx->messengerMessageId());
    }

    public function test_context_is_restored_even_when_the_handler_throws(): void
    {
        $this->ctx->begin(ActivityActorType::SCHEDULED_TASK);
        $before = $this->ctx->correlationId();

        try {
            $this->bus(static function (): never { throw new \RuntimeException('handler failure'); })
                ->dispatch(new Envelope(new \stdClass(), [new ReceivedStamp('async'), new CorrelationStamp(UuidV7::generate())]));
            self::fail('exception expected');
        } catch (\Throwable) {
        }

        self::assertSame($before, $this->ctx->correlationId());
    }

    public function test_successive_messages_never_share_a_context(): void
    {
        $bus = $this->bus();
        $a = UuidV7::generate();
        $b = UuidV7::generate();

        $bus->dispatch(new Envelope(new \stdClass(), [new ReceivedStamp('async'), new CorrelationStamp($a, ['absence:58' => 1])]));
        $bus->dispatch(new Envelope(new \stdClass(), [new ReceivedStamp('async'), new CorrelationStamp($b)]));
        $bus->dispatch(new Envelope(new \stdClass(), [new ReceivedStamp('async')])); // pre-H1 message, no stamp

        self::assertSame($a, $this->seen[0]['correlationId']);
        self::assertSame($b, $this->seen[1]['correlationId']);
        self::assertNull($this->seen[1]['anchor'], 'anchor of message A must not leak into B');
        self::assertNotContains($this->seen[2]['correlationId'], [$a, $b]);
        self::assertTrue(UuidV7::isValid($this->seen[2]['correlationId']));
    }
}
