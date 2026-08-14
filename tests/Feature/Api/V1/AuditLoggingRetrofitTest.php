<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\CashShift;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Cubre el retrofit de AuditLogger::log() en los controllers de negocio (ver
 * AuditActionDictionary para la lista completa de acciones instrumentadas).
 * Cada caso solo verifica que la fila de log_actions se crea con la accion y
 * el business_id correctos - el comportamiento de cada endpoint ya esta
 * cubierto por su propio test file.
 */
class AuditLoggingRetrofitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::sync();
    }

    private function admin(): array
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $user->assignRole('admin');

        return [$business, $user];
    }

    public function test_creating_a_sale_logs_sale_created(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseHas('log_actions', ['action' => 'sale.created', 'business_id' => $business->id]);
    }

    public function test_reversing_a_sale_logs_sale_reversed(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $sale = $this->actingAs($user, 'sanctum')->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/sales/{$sale['id']}/reverse")->assertNoContent();

        $this->assertDatabaseHas('log_actions', ['action' => 'sale.reversed', 'business_id' => $business->id]);
    }

    public function test_opening_a_tab_logs_tab_opened(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertDatabaseHas('log_actions', ['action' => 'tab.opened', 'business_id' => $business->id]);
    }

    public function test_adding_items_to_a_tab_logs_tab_items_added(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/items", [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'tab.items_added', 'business_id' => $business->id]);
    }

    public function test_syncing_tab_items_logs_tab_items_synced(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/open-tabs/{$tab['id']}/items", [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'tab.items_synced', 'business_id' => $business->id]);
    }

    public function test_recording_a_partial_payment_logs_tab_partial_payment(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10, 'price' => 10000]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/partial-payments", [
            'amount' => 1000,
            'payment_method' => 'cash',
        ])->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'tab.partial_payment', 'business_id' => $business->id]);
    }

    public function test_closing_a_tab_logs_tab_closed(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/open-tabs/{$tab['id']}/close", ['payment_method' => 'cash'])->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'tab.closed', 'business_id' => $business->id]);
    }

    public function test_cancelling_a_tab_logs_tab_cancelled(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id, 'stock' => 10]);

        $tab = $this->actingAs($user, 'sanctum')->postJson('/api/v1/open-tabs', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->json();

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/open-tabs/{$tab['id']}")->assertNoContent();

        $this->assertDatabaseHas('log_actions', ['action' => 'tab.cancelled', 'business_id' => $business->id]);
    }

    public function test_collecting_a_receivable_logs_receivable_collected(): void
    {
        [$business, $user] = $this->admin();
        $sale = Sale::factory()->create(['business_id' => $business->id]);
        $receivable = Receivable::factory()->create(['business_id' => $business->id, 'sale_id' => $sale->id, 'status' => 'pending']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/receivables/{$receivable->id}/collect", ['payment_method' => 'cash'])
            ->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'receivable.collected', 'business_id' => $business->id]);
    }

    public function test_opening_a_cash_shift_logs_cash_shift_opened(): void
    {
        [$business, $user] = $this->admin();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-shifts', ['opening_cash' => 50000])->assertCreated();

        $this->assertDatabaseHas('log_actions', ['action' => 'cash_shift.opened', 'business_id' => $business->id]);
    }

    public function test_closing_a_cash_shift_logs_cash_shift_closed(): void
    {
        [$business, $user] = $this->admin();
        $shift = $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-shifts', ['opening_cash' => 50000])->json();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/cash-shifts/{$shift['id']}/close", ['counted_cash' => 50000])
            ->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'cash_shift.closed', 'business_id' => $business->id]);
    }

    public function test_updating_a_cash_shift_logs_cash_shift_updated(): void
    {
        [$business, $user] = $this->admin();
        $shift = CashShift::factory()->closed()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/cash-shifts/{$shift->id}", ['opening_cash' => 60000])
            ->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'cash_shift.updated', 'business_id' => $business->id]);
    }

    public function test_deleting_a_cash_shift_logs_cash_shift_deleted(): void
    {
        [$business, $user] = $this->admin();
        $shift = CashShift::factory()->closed()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/cash-shifts/{$shift->id}")->assertNoContent();

        $this->assertDatabaseHas('log_actions', ['action' => 'cash_shift.deleted', 'business_id' => $business->id]);
    }

    public function test_registering_a_cash_closing_logs_cash_closing_created(): void
    {
        [$business, $user] = $this->admin();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-closings', [
            'date' => now()->toDateString(),
            'opening_cash' => 50000,
            'actual_cash' => 50000,
            'base_for_next_day' => 50000,
        ])->assertCreated();

        $this->assertDatabaseHas('log_actions', ['action' => 'cash_closing.created', 'business_id' => $business->id]);
    }

    public function test_updating_a_cash_closing_logs_cash_closing_updated(): void
    {
        [$business, $user] = $this->admin();
        $closing = $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-closings', [
            'date' => now()->toDateString(),
            'opening_cash' => 50000,
            'actual_cash' => 50000,
            'base_for_next_day' => 50000,
        ])->json();

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/cash-closings/{$closing['id']}", [
            'opening_cash' => 50000,
            'actual_cash' => 60000,
            'base_for_next_day' => 50000,
        ])->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'cash_closing.updated', 'business_id' => $business->id]);
    }

    public function test_undoing_a_cash_closing_logs_cash_closing_undone(): void
    {
        [$business, $user] = $this->admin();
        $closing = $this->actingAs($user, 'sanctum')->postJson('/api/v1/cash-closings', [
            'date' => now()->toDateString(),
            'opening_cash' => 50000,
            'actual_cash' => 50000,
            'base_for_next_day' => 50000,
        ])->json();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/cash-closings/{$closing['id']}/undo")->assertNoContent();

        $this->assertDatabaseHas('log_actions', ['action' => 'cash_closing.undone', 'business_id' => $business->id]);
    }

    public function test_creating_an_expense_logs_expense_created(): void
    {
        [$business, $user] = $this->admin();
        $type = ExpenseType::factory()->global()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/expenses', [
            'date' => '2026-01-15',
            'description' => 'Arriendo',
            'value' => 100000,
            'type_id' => $type->id,
        ])->assertCreated();

        $this->assertDatabaseHas('log_actions', ['action' => 'expense.created', 'business_id' => $business->id]);
    }

    public function test_updating_an_expense_logs_expense_updated(): void
    {
        [$business, $user] = $this->admin();
        $expense = Expense::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/expenses/{$expense->id}", ['description' => 'Actualizado'])
            ->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'expense.updated', 'business_id' => $business->id]);
    }

    public function test_deleting_an_expense_logs_expense_deleted(): void
    {
        [$business, $user] = $this->admin();
        $expense = Expense::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/expenses/{$expense->id}")->assertNoContent();

        $this->assertDatabaseHas('log_actions', ['action' => 'expense.deleted', 'business_id' => $business->id]);
    }

    public function test_creating_a_product_logs_product_created(): void
    {
        [$business, $user] = $this->admin();
        $category = ProductCategory::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/products', [
            'name' => 'Producto nuevo',
            'price' => 5000,
            'category_id' => $category->id,
        ])->assertCreated();

        $this->assertDatabaseHas('log_actions', ['action' => 'product.created', 'business_id' => $business->id]);
    }

    public function test_updating_a_product_logs_product_updated(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/products/{$product->id}", ['price' => 9999])->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'product.updated', 'business_id' => $business->id]);
    }

    public function test_duplicating_a_product_logs_product_duplicated(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/products/{$product->id}/duplicate")->assertCreated();

        $this->assertDatabaseHas('log_actions', ['action' => 'product.duplicated', 'business_id' => $business->id]);
    }

    public function test_deleting_a_product_logs_product_deleted(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

        $this->assertDatabaseHas('log_actions', ['action' => 'product.deleted', 'business_id' => $business->id]);
    }

    public function test_closing_an_accounting_month_logs_accounting_month_closed(): void
    {
        [$business, $user] = $this->admin();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/accounting/close-month', ['year' => 2026, 'month' => 3])
            ->assertCreated();

        $this->assertDatabaseHas('log_actions', ['action' => 'accounting.month_closed', 'business_id' => $business->id]);
    }

    public function test_updating_kitchen_status_logs_kitchen_status_changed(): void
    {
        [$business, $user] = $this->admin();
        $product = Product::factory()->create(['business_id' => $business->id]);
        $sale = Sale::factory()->create(['business_id' => $business->id, 'status' => 'open']);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/kitchen/tickets/{$sale->id}/status", ['kitchen_status' => 'ready'])
            ->assertOk();

        $this->assertDatabaseHas('log_actions', ['action' => 'kitchen.status_changed', 'business_id' => $business->id]);
    }
}
