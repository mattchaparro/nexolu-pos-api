<?php

namespace Tests\Feature\Api\V1;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_authenticated_user_can_list_settings(): void
    {
        Setting::factory()->create(['title' => 'Alpha']);
        Setting::factory()->create(['title' => 'Beta']);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/settings');

        $response->assertOk()->assertJsonCount(2);
    }

    public function test_admin_can_update_an_editable_setting(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $setting = Setting::factory()->create(['value' => 'old', 'editable' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/settings/{$setting->slug}", ['value' => 'new'])
            ->assertOk()
            ->assertJsonPath('value', 'new');

        $this->assertSame('new', $setting->fresh()->value);
    }

    public function test_non_admin_cannot_update_a_setting(): void
    {
        $setting = Setting::factory()->create(['value' => 'old', 'editable' => true]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/settings/{$setting->slug}", ['value' => 'new'])
            ->assertForbidden();
    }

    public function test_non_editable_setting_cannot_be_updated(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $setting = Setting::factory()->create(['value' => 'old', 'editable' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/settings/{$setting->slug}", ['value' => 'new'])
            ->assertStatus(422);

        $this->assertSame('old', $setting->fresh()->value);
    }
}
