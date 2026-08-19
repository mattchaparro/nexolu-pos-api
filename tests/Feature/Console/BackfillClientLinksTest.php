<?php

namespace Tests\Feature\Console;

use App\Models\Business;
use App\Models\Client;
use App\Models\Layaway;
use App\Models\Receivable;
use App\Models\Sale;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BackfillClientLinksTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_links_rows_matching_a_client_phone_across_the_three_tables(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->create(['business_id' => $business->id, 'phone' => '300 123 4567']);
        $sale = Sale::factory()->create(['business_id' => $business->id, 'customer_phone' => '3001234567']);
        $layaway = Layaway::factory()->create(['business_id' => $business->id, 'customer_phone' => '(300) 123-4567']);
        $receivable = Receivable::factory()->create(['business_id' => $business->id, 'customer_phone' => '3001234567']);

        $this->artisan('clients:backfill-links')->assertSuccessful();

        $this->assertSame($client->id, $sale->fresh()->client_id);
        $this->assertSame($client->id, $layaway->fresh()->client_id);
        $this->assertSame($client->id, $receivable->fresh()->client_id);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $business = Business::factory()->create();
        $client = Client::factory()->create(['business_id' => $business->id, 'phone' => '3001234567']);
        $sale = Sale::factory()->create(['business_id' => $business->id, 'customer_phone' => '3001234567']);

        $this->artisan('clients:backfill-links', ['--dry-run' => true])
            ->expectsOutputToContain('se vincularian')
            ->assertSuccessful();

        $this->assertNull($sale->fresh()->client_id);
        $this->assertNotNull($client);
    }

    public function test_a_phone_shared_by_two_clients_is_left_ambiguous(): void
    {
        $business = Business::factory()->create();
        Client::factory()->create(['business_id' => $business->id, 'phone' => '3001234567']);
        Client::factory()->create(['business_id' => $business->id, 'phone' => '3001234567']);
        $sale = Sale::factory()->create(['business_id' => $business->id, 'customer_phone' => '3001234567']);

        $this->artisan('clients:backfill-links')
            ->expectsOutputToContain('1 ambiguos')
            ->assertSuccessful();

        $this->assertNull($sale->fresh()->client_id);
    }

    public function test_it_never_overwrites_an_existing_client_id(): void
    {
        $business = Business::factory()->create();
        $existingClient = Client::factory()->create(['business_id' => $business->id]);
        $matchingClient = Client::factory()->create(['business_id' => $business->id, 'phone' => '3001234567']);
        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'customer_phone' => '3001234567',
            'client_id' => $existingClient->id,
        ]);

        $this->artisan('clients:backfill-links')->assertSuccessful();

        $this->assertSame($existingClient->id, $sale->fresh()->client_id);
        $this->assertNotSame($matchingClient->id, $sale->fresh()->client_id);
    }

    public function test_the_business_option_limits_the_scope(): void
    {
        $target = Business::factory()->create();
        $other = Business::factory()->create();
        Client::factory()->create(['business_id' => $target->id, 'phone' => '3001234567']);
        Client::factory()->create(['business_id' => $other->id, 'phone' => '3001234567']);
        $targetSale = Sale::factory()->create(['business_id' => $target->id, 'customer_phone' => '3001234567']);
        $otherSale = Sale::factory()->create(['business_id' => $other->id, 'customer_phone' => '3001234567']);

        $this->artisan('clients:backfill-links', ['--business' => $target->id])->assertSuccessful();

        $this->assertNotNull($targetSale->fresh()->client_id);
        $this->assertNull($otherSale->fresh()->client_id);
    }
}
