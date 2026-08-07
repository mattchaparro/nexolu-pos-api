<?php

namespace Tests\Unit\Support\Validation;

use App\Models\Business;
use App\Models\Discount;
use App\Models\ExpenseType;
use App\Models\Product;
use App\Support\Validation\BusinessScopedExists;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class BusinessScopedExistsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_for_accepts_a_row_belonging_to_the_business(): void
    {
        $business = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $business->id]);

        $validator = Validator::make(
            ['product_id' => $product->id],
            ['product_id' => BusinessScopedExists::for('products', $business->id)]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_for_rejects_a_row_from_another_business(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $foreignProduct = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $validator = Validator::make(
            ['product_id' => $foreignProduct->id],
            ['product_id' => BusinessScopedExists::for('products', $business->id)]
        );

        $this->assertTrue($validator->fails());
    }

    public function test_for_applies_extra_where_constraints(): void
    {
        $business = Business::factory()->create();
        $itemDiscount = Discount::factory()->itemScoped()->create(['business_id' => $business->id]);
        $cartDiscount = Discount::factory()->create(['business_id' => $business->id, 'scope' => 'cart']);

        $validator = Validator::make(
            ['discount_id' => $cartDiscount->id],
            ['discount_id' => BusinessScopedExists::for('discounts', $business->id, ['scope' => 'item'])]
        );

        $this->assertTrue($validator->fails(), 'a cart-scoped discount must not satisfy a scope=item constraint');

        $validator = Validator::make(
            ['discount_id' => $itemDiscount->id],
            ['discount_id' => BusinessScopedExists::for('discounts', $business->id, ['scope' => 'item'])]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_for_or_global_accepts_a_row_with_a_null_business_id(): void
    {
        $business = Business::factory()->create();
        $globalType = ExpenseType::factory()->global()->create();

        $validator = Validator::make(
            ['type_id' => $globalType->id],
            ['type_id' => BusinessScopedExists::forOrGlobal('expense_types', $business->id)]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_for_or_global_rejects_a_row_owned_by_another_business(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $foreignType = ExpenseType::factory()->create(['business_id' => $otherBusiness->id]);

        $validator = Validator::make(
            ['type_id' => $foreignType->id],
            ['type_id' => BusinessScopedExists::forOrGlobal('expense_types', $business->id)]
        );

        $this->assertTrue($validator->fails());
    }
}
