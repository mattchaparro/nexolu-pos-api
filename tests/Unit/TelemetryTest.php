<?php

namespace Tests\Unit;

use App\Support\Telemetry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class TelemetryTest extends TestCase
{
    protected function tearDown(): void
    {
        SentrySdk::getCurrentHub()->setSpan(null);
        parent::tearDown();
    }

    public function test_without_an_active_trace_it_just_runs_the_callback(): void
    {
        SentrySdk::getCurrentHub()->setSpan(null);

        $result = Telemetry::span('catalog.load', 'Productos', fn (?Span $span) => $span === null ? 'sin span' : 'con span');

        $this->assertSame('sin span', $result);
    }

    public function test_it_records_a_child_span_with_its_data_and_restores_the_parent(): void
    {
        $transaction = $this->activeTransaction();

        $result = Telemetry::span('catalog.load', 'Productos vendibles', function (?Span $span) {
            Telemetry::annotate(['catalog.cache_hit' => false]);

            return 42;
        }, ['catalog.products' => 7, 'ignorado' => null]);

        $this->assertSame(42, $result);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());

        $child = $this->childSpans($transaction)[0];
        $this->assertSame('catalog.load', $child->getOp());
        $this->assertSame('Productos vendibles', $child->getDescription());
        $this->assertSame(7, $child->getData('catalog.products'));
        $this->assertFalse($child->getData('catalog.cache_hit'));
        $this->assertNull($child->getData('ignorado'));
        $this->assertEquals(SpanStatus::ok(), $child->getStatus());
        $this->assertNotNull($child->getEndTimestamp());
    }

    public function test_a_failure_marks_the_span_and_still_restores_the_parent(): void
    {
        $transaction = $this->activeTransaction();

        try {
            Telemetry::span('sale.items', 'Lineas', fn () => throw new RuntimeException('sin stock'));
            $this->fail('La excepcion debia propagarse.');
        } catch (RuntimeException $error) {
            $this->assertSame('sin stock', $error->getMessage());
        }

        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());

        $child = $this->childSpans($transaction)[0];
        $this->assertEquals(SpanStatus::internalError(), $child->getStatus());
        $this->assertSame(RuntimeException::class, $child->getData('error.type'));
        $this->assertNotNull($child->getEndTimestamp());
    }

    private function activeTransaction(): Transaction
    {
        $transaction = new Transaction(TransactionContext::make()->setName('prueba')->setOp('http.server'));
        $transaction->initSpanRecorder();
        SentrySdk::getCurrentHub()->setSpan($transaction);

        return $transaction;
    }

    /** @return list<Span> */
    private function childSpans(Transaction $transaction): array
    {
        return array_values(array_filter(
            $transaction->getSpanRecorder()->getSpans(),
            fn (Span $span) => $span !== $transaction,
        ));
    }
}
