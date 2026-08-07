<?php

namespace Tests\Feature\Support;

use App\Support\CategoryIconResolver;
use Tests\TestCase;

class CategoryIconResolverTest extends TestCase
{
    public function test_translates_a_known_material_icon_name(): void
    {
        $this->assertSame('pi pi-shop', CategoryIconResolver::resolve('local_bar'));
        $this->assertSame('pi pi-box', CategoryIconResolver::resolve('inventory_2'));
    }

    public function test_passes_through_a_value_already_in_primeicons_format(): void
    {
        $this->assertSame('pi pi-glass-water', CategoryIconResolver::resolve('pi pi-glass-water'));
        $this->assertSame('pi pi-wrench', CategoryIconResolver::resolve('pi-wrench'));
    }

    public function test_falls_back_to_the_default_icon_for_null_empty_or_unknown_values(): void
    {
        $this->assertSame(CategoryIconResolver::DEFAULT_ICON, CategoryIconResolver::resolve(null));
        $this->assertSame(CategoryIconResolver::DEFAULT_ICON, CategoryIconResolver::resolve(''));
        $this->assertSame(CategoryIconResolver::DEFAULT_ICON, CategoryIconResolver::resolve('un_icono_que_no_existe'));
    }
}
