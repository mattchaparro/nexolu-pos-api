<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\ActsAsSuperAdmin;
use Tests\TestCase;

class AnnouncementsTest extends TestCase
{
    use ActsAsSuperAdmin, DatabaseTransactions;

    public function test_full_crud_lifecycle(): void
    {
        $admin = $this->superadmin();

        $store = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/announcements', [
            'title' => 'Mantenimiento programado',
            'body' => 'El sistema estará en mantenimiento el sábado.',
            'audience' => 'admin',
        ]);
        $store->assertCreated()->assertJsonPath('audience', 'admin');

        $id = $store->json('id');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/superadmin/announcements')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/superadmin/announcements/{$id}", [
                'title' => 'Mantenimiento reprogramado',
                'body' => 'Nueva fecha: domingo.',
                'audience' => 'all',
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('title', 'Mantenimiento reprogramado')
            ->assertJsonPath('active', false);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/superadmin/announcements/{$id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('announcements', ['id' => $id]);
    }

    public function test_invalid_audience_is_rejected(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/superadmin/announcements', [
            'title' => 'X',
            'body' => 'Y',
            'audience' => 'not-a-real-audience',
        ])->assertStatus(422);
    }

    public function test_regular_business_user_cannot_manage_announcements(): void
    {
        Announcement::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/superadmin/announcements')
            ->assertForbidden();
    }
}
