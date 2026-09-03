<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_superadmin_can_manage_guide_categories_and_articles(): void
    {
        $admin = $this->superadmin();

        $category = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/support-guides/categories', [
            'slug' => 'primeros-pasos',
            'title' => 'Primeros pasos',
        ])->assertCreated()->json();

        $article = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/support-guides/articles', [
            'support_guide_category_id' => $category['id'],
            'slug' => 'como-abrir-turno',
            'title' => 'Cómo abrir un turno',
            'body' => 'Pasos para abrir un turno de caja...',
        ])->assertCreated();

        $index = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/superadmin/support-guides');
        $index->assertOk()->assertJsonCount(1);
        $this->assertCount(1, $index->json('0.articles'));

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/support-guides/articles/{$article->json('id')}")
            ->assertNoContent();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/support-guides/categories/{$category['id']}")
            ->assertNoContent();
    }

    public function test_regular_user_cannot_access_superadmin_support_endpoints(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/superadmin/support-guides')
            ->assertForbidden();
    }
}
