<?php

namespace App\Tests\Unit\Activity;

use App\Activity\CorrelationContext;
use App\Activity\UuidV7;
use App\Enum\ActivityActorType;
use PHPUnit\Framework\TestCase;

/** H1 (D-126) */
final class CorrelationContextTest extends TestCase
{
    public function test_uuid_v7_format_version_and_uniqueness(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $id = UuidV7::generate();
            self::assertTrue(UuidV7::isValid($id), $id);
            self::assertSame('7', $id[14]);
            self::assertContains($id[19], ['8', '9', 'a', 'b']);
            $ids[$id] = true;
        }
        self::assertCount(200, $ids);
    }

    public function test_begin_always_generates_a_fresh_server_side_id(): void
    {
        $ctx = new CorrelationContext();
        $ctx->begin(ActivityActorType::USER, 'client-request-123');
        $first = $ctx->correlationId();

        $ctx->begin(ActivityActorType::USER, 'client-request-123');
        self::assertNotSame($first, $ctx->correlationId());
        self::assertSame('client-request-123', $ctx->requestId());
    }

    public function test_an_invalid_inherited_correlation_id_is_replaced(): void
    {
        $ctx = new CorrelationContext();
        $ctx->beginFromMessage('not-a-uuid', [], null);

        self::assertTrue(UuidV7::isValid($ctx->correlationId()));
    }

    public function test_reset_clears_everything_including_anchors(): void
    {
        $ctx = new CorrelationContext();
        $ctx->begin(ActivityActorType::SCHEDULED_TASK, 'req-abcdefgh');
        $ctx->registerAnchor('absence:1', 10);
        $id = $ctx->correlationId();

        $ctx->reset();

        self::assertFalse($ctx->isActive());
        self::assertNull($ctx->anchor('absence:1'));
        self::assertNull($ctx->requestId());
        self::assertNotSame($id, $ctx->correlationId());
    }

    public function test_export_restore_round_trip(): void
    {
        $ctx = new CorrelationContext();
        $ctx->begin(ActivityActorType::USER, 'req-12345678');
        $ctx->registerAnchor('absence:58', 7);
        $snapshot = $ctx->export();
        $id = $ctx->correlationId();

        $ctx->beginFromMessage(UuidV7::generate(), ['absence:99' => 1], 'msg-1', 42);
        self::assertSame(42, $ctx->messageCausationEventId());
        self::assertSame(ActivityActorType::WORKER, $ctx->defaultActorType());

        $ctx->restore($snapshot);
        self::assertSame($id, $ctx->correlationId());
        self::assertSame(7, $ctx->anchor('absence:58'));
        self::assertNull($ctx->anchor('absence:99'));
        self::assertNull($ctx->messageCausationEventId());
    }

    public function test_anchor_count_is_bounded(): void
    {
        $ctx = new CorrelationContext();
        for ($i = 0; $i < 80; $i++) {
            $ctx->registerAnchor('absence:' . $i, $i);
        }
        self::assertCount(50, $ctx->anchors());
    }
}
