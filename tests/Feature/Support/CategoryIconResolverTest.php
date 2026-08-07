<?php

namespace Tests\Feature\Support;

use App\Support\CategoryIconResolver;
use Tests\TestCase;

class CategoryIconResolverTest extends TestCase
{
    public function test_passes_through_any_non_empty_value_unchanged(): void
    {
        $this->assertSame('local_bar', CategoryIconResolver::resolve('local_bar'));
        $this->assertSame('un_icono_cualquiera', CategoryIconResolver::resolve('un_icono_cualquiera'));
    }

    public function test_falls_back_to_the_default_icon_for_null_or_empty_values(): void
    {
        $this->assertSame(CategoryIconResolver::DEFAULT_ICON, CategoryIconResolver::resolve(null));
        $this->assertSame(CategoryIconResolver::DEFAULT_ICON, CategoryIconResolver::resolve(''));
        $this->assertSame(CategoryIconResolver::DEFAULT_ICON, CategoryIconResolver::resolve('   '));
    }
}
