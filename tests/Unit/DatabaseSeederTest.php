<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Sin un superadmin sembrado, /superadmin/* siempre da 403 en local -
     * ya paso una vez (issue real reportado), no debe volver a pasar en
     * silencio.
     */
    public function test_seeding_creates_a_usable_superadmin_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $superadmin = User::where('email', 'superadmin@example.com')->first();

        $this->assertNotNull($superadmin);
        $this->assertNull($superadmin->business_id);
        $this->assertTrue($superadmin->hasRole('superadmin'));
    }

    public function test_seeding_creates_a_business_owner_scoped_to_a_business(): void
    {
        $this->seed(DatabaseSeeder::class);

        $owner = User::where('email', 'test@example.com')->first();

        $this->assertNotNull($owner);
        $this->assertNotNull($owner->business_id);
        $this->assertTrue($owner->is_business_owner);
        $this->assertTrue($owner->hasRole('admin'));
    }
}
