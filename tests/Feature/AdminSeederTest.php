<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_from_config_values(): void
    {
        config()->set('raffle.seed_admin.email', 'owner@example.test');
        config()->set('raffle.seed_admin.password', 'correct-horse-battery');

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->password));
    }

    public function test_skips_admin_when_no_email_configured(): void
    {
        config()->set('raffle.seed_admin.email', null);
        config()->set('raffle.seed_admin.password', null);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::where('role', 'admin')->count());
        $this->assertFalse(User::where('email', 'admin@wdscompetitions.co.uk')->exists());
    }

    public function test_keeps_existing_password_when_none_configured(): void
    {
        User::create([
            'name' => 'Existing', 'first_name' => 'Ex', 'last_name' => 'Isting', 'email' => 'owner@example.test',
            'password' => 'original-password', 'role' => 'customer', 'date_of_birth' => '1990-01-01',
        ]);
        config()->set('raffle.seed_admin.email', 'owner@example.test');
        config()->set('raffle.seed_admin.password', null);

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('original-password', $admin->password));
    }
}
