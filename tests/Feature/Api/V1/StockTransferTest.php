<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockMovementReason;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Traslados entre sedes. Lo que se prueba aqui no es "que se pueda mover
 * stock" sino que sea IMPOSIBLE que un traslado descuadre el inventario: el
 * total del negocio no cambia, ninguna sede queda en negativo, y las dos
 * mitades quedan atadas al mismo traslado.
 */
class StockTransferTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        BranchContext::forget();

        parent::tearDown();
    }

    public function test_a_transfer_moves_stock_between_branches_without_changing_the_total(): void
    {
        [$business, $main, $second, $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $main->id,
            'to_branch_id' => $second->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'notes' => 'Reposicion del punto del centro comercial',
        ])->assertCreated();

        $this->assertSame(6.0, BranchStock::quantity($main->id, 'product_id', $product->id));
        $this->assertSame(4.0, BranchStock::quantity($second->id, 'product_id', $product->id));
        $this->assertSame(10, (int) $product->fresh()->stock, 'Un traslado no crea ni destruye inventario.');

        $transferId = $response->json('id');
        $movements = StockMovement::withoutGlobalScopes()->where('stock_transfer_id', $transferId)->get();

        $this->assertCount(2, $movements, 'Las dos mitades quedan atadas al mismo traslado.');
        $this->assertEqualsCanonicalizing([-4.0, 4.0], $movements->pluck('quantity')->map(fn ($q) => (float) $q)->all());
        $this->assertEqualsCanonicalizing([$main->id, $second->id], $movements->pluck('branch_id')->all());

        $reasons = StockMovementReason::whereIn('id', $movements->pluck('stock_movement_reason_id'))->pluck('code');
        $this->assertEqualsCanonicalizing(['transfer_out', 'transfer_in'], $reasons->all());
    }

    public function test_a_transfer_larger_than_the_origin_stock_is_rejected(): void
    {
        [$business, $main, $second, $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['stock' => 3]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $main->id,
            'to_branch_id' => $second->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(3.0, BranchStock::quantity($main->id, 'product_id', $product->id));
        $this->assertSame(0.0, BranchStock::quantity($second->id, 'product_id', $product->id));
    }

    public function test_a_failed_line_rolls_back_the_whole_transfer(): void
    {
        [$business, $main, $second, $admin] = $this->scenario();
        $ok = Product::factory()->for($business)->create(['stock' => 10]);
        $short = Product::factory()->for($business)->create(['stock' => 1]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $main->id,
            'to_branch_id' => $second->id,
            'items' => [
                ['product_id' => $ok->id, 'quantity' => 2],
                ['product_id' => $short->id, 'quantity' => 9],
            ],
        ])->assertStatus(422);

        // Un traslado a medias es peor que uno rechazado: el papel y el
        // sistema dejarian de coincidir sin que nadie lo note.
        $this->assertSame(10.0, BranchStock::quantity($main->id, 'product_id', $ok->id));
        $this->assertSame(0.0, BranchStock::quantity($second->id, 'product_id', $ok->id));
    }

    public function test_the_same_branch_cannot_be_origin_and_destination(): void
    {
        [$business, $main, , $admin] = $this->scenario();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $main->id,
            'to_branch_id' => $main->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('to_branch_id');
    }

    public function test_a_branch_of_another_business_is_rejected(): void
    {
        [$business, $main, , $admin] = $this->scenario();
        $foreign = Branch::factory()->create();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $main->id,
            'to_branch_id' => $foreign->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('to_branch_id');
    }

    public function test_a_product_of_another_business_is_rejected(): void
    {
        [, $main, $second, $admin] = $this->scenario();
        $foreignProduct = Product::factory()->create(['stock' => 10]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $main->id,
            'to_branch_id' => $second->id,
            'items' => [['product_id' => $foreignProduct->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_a_business_without_the_multi_branch_feature_has_no_transfers(): void
    {
        $business = Business::factory()->create(['feature_flags' => ['inventory' => true, 'multi_branch' => false]]);
        Branch::factory()->for($business)->main()->create();
        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/stock-transfers')->assertForbidden();
    }

    public function test_an_employee_only_sees_transfers_of_their_branch(): void
    {
        [$business, $main, $second, $admin] = $this->scenario();
        $third = Branch::factory()->for($business)->create();
        $product = Product::factory()->for($business)->create(['stock' => 10]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/stock-transfers', [
            'from_branch_id' => $main->id,
            'to_branch_id' => $second->id,
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $employee = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => false]);
        $employee->assignRole('employee');
        $employee->givePermissionTo('inventory.view');
        $employee->branches()->attach([$second->id, $third->id]);

        // La sede que RECIBIO tambien lo ve: sin eso no podria saber de donde
        // salio lo que le entro al inventario.
        $this->actingAs($employee, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $second->id)
            ->getJson('/api/v1/stock-transfers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($employee, 'sanctum')
            ->withHeader('X-Branch-Id', (string) $third->id)
            ->getJson('/api/v1/stock-transfers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** @return array{0: Business, 1: Branch, 2: Branch, 3: User} */
    private function scenario(): array
    {
        $business = Business::factory()->create([
            'feature_flags' => ['inventory' => true, 'multi_branch' => true],
        ]);
        $main = Branch::factory()->for($business)->main()->create();
        $second = Branch::factory()->for($business)->create();

        $admin = User::factory()->create(['business_id' => $business->id, 'is_business_owner' => true]);
        $admin->assignRole('admin');

        return [$business, $main, $second, $admin];
    }
}
