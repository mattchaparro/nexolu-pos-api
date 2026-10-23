<?php

namespace Tests\Unit;

use App\Support\SentryTracesSampler;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;
use Tests\TestCase;

class SentryTracesSamplerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['sentry.traces_sample_rate' => 0.2]);
    }

    private function rateFor(string $op, string $name, ?bool $parentSampled = null): float
    {
        $context = SamplingContext::getDefault(TransactionContext::make()->setOp($op)->setName($name));
        $context->setParentSampled($parentSampled);

        return SentryTracesSampler::sample($context);
    }

    public function test_counter_flows_get_more_traces_than_the_default(): void
    {
        $this->assertSame(0.5, $this->rateFor('http.server', '/api/v1/products/sellable'));
        $this->assertSame(0.5, $this->rateFor('http.server', '/api/v1/open-tabs/77/items'));
        $this->assertSame(0.5, $this->rateFor('http.server', '/api/v1/sales'));
        $this->assertSame(0.5, $this->rateFor('http.server', '/api/v1/cash-shifts/5/close'));
    }

    public function test_everything_else_uses_the_configured_rate(): void
    {
        $this->assertSame(0.2, $this->rateFor('http.server', '/api/v1/business'));
        $this->assertSame(0.2, $this->rateFor('http.server', '/api/v1/sales/history'));
    }

    public function test_health_checks_and_scheduled_commands_are_mostly_dropped(): void
    {
        $this->assertSame(0.0, $this->rateFor('http.server', '/up'));
        $this->assertSame(0.05, $this->rateFor('console.command.scheduled', 'inventory:send-low-stock-alerts'));
    }

    public function test_the_browser_decision_wins_so_traces_are_not_cut_in_half(): void
    {
        $this->assertSame(1.0, $this->rateFor('http.server', '/api/v1/business', parentSampled: true));
        $this->assertSame(0.0, $this->rateFor('http.server', '/api/v1/products/sellable', parentSampled: false));
    }
}
