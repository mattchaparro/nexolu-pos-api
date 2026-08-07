<?php

namespace Tests\Feature\Mail;

use App\Mail\LowStockAlertMail;
use App\Models\Business;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LowStockAlertMailTest extends TestCase
{
    use DatabaseTransactions;

    public function test_includes_a_signed_snooze_link_per_day_option(): void
    {
        $business = Business::factory()->create();
        $items = new Collection([
            ['kind' => 'product', 'id' => 1, 'name' => 'Papa', 'stock' => 2.0, 'threshold' => 5, 'unit' => null],
        ]);

        $mail = new LowStockAlertMail($business, $items);

        $mail->assertSeeInHtml('3 dias');
        $mail->assertSeeInHtml('7 dias');
        $mail->assertSeeInHtml('15 dias');
        $mail->assertSeeInHtml('30 dias');
        $mail->assertSeeInHtml("notifications/low-stock/{$business->id}/snooze");
    }
}
