<?php

namespace Tests\Feature\Services;

use App\Models\Business;
use App\Models\User;
use App\Services\AiMessagePackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AiMessagePackServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AiMessagePackService $packs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packs = app(AiMessagePackService::class);
    }

    public function test_credit_increases_the_balance_and_logs_the_purchase(): void
    {
        $business = Business::factory()->create(['ai_message_pack_balance' => 500]);
        $user = User::factory()->create(['business_id' => $business->id]);

        $this->packs->credit($business, 1000, 15000, $user->id, 'compra desde ajustes');

        $this->assertSame(1500, $business->fresh()->ai_message_pack_balance);
        $this->assertDatabaseHas('ai_message_pack_purchases', [
            'business_id' => $business->id,
            'messages' => 1000,
            'price_cop' => 15000,
            'created_by_user_id' => $user->id,
            'notes' => 'compra desde ajustes',
        ]);
    }

    public function test_consume_one_decrements_the_balance_and_returns_true(): void
    {
        $business = Business::factory()->create(['ai_message_pack_balance' => 3]);

        $this->assertTrue($this->packs->consumeOne($business));
        $this->assertSame(2, $business->fresh()->ai_message_pack_balance);
    }

    public function test_consume_one_returns_false_and_never_goes_negative_when_balance_is_zero(): void
    {
        $business = Business::factory()->create(['ai_message_pack_balance' => 0]);

        $this->assertFalse($this->packs->consumeOne($business));
        $this->assertSame(0, $business->fresh()->ai_message_pack_balance);
    }
}
