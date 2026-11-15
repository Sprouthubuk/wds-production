<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Winner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Photos uploaded in Admin get a 720px-wide JPEG beside them for cards and thumbnails. The original is stored as
 * name-full.ext so the website knows the small copy exists (web/src/lib/images.ts thumb()).
 */
class UploadedPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is needed to make test images.');
        }
        Storage::fake('public');
    }

    private function assertHasSmallCopy(string $url, int $expectedHeight): void
    {
        $this->assertMatchesRegularExpression('#/storage/(competitions|winners)/[A-Za-z0-9]{40}-full\.jpg$#', $url);
        $path = substr($url, strpos($url, '/storage/') + strlen('/storage/'));
        Storage::disk('public')->assertExists($path);
        $small = preg_replace('/-full\.jpg$/', '-720.jpg', $path);
        Storage::disk('public')->assertExists($small);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($small));
        $this->assertSame([720, $expectedHeight], [$w, $h]);
    }

    public function test_a_competition_photo_gets_a_720px_copy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $src = $this->actingAs($admin)->post('/api/admin/uploads/competition-image', ['image' => UploadedFile::fake()->image('car.jpg', 2400, 1600)])
            ->assertOk()->json('src');
        $this->assertHasSmallCopy($src, 480);
    }

    public function test_a_small_photo_is_never_scaled_up(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $src = $this->actingAs($admin)->post('/api/admin/uploads/competition-image', ['image' => UploadedFile::fake()->image('tiny.jpg', 400, 300)])
            ->assertOk()->json('src');
        $path = substr($src, strpos($src, '/storage/') + strlen('/storage/'));
        [$w] = getimagesizefromstring(Storage::disk('public')->get(preg_replace('/-full\.jpg$/', '-720.jpg', $path)));
        $this->assertSame(400, $w);
    }

    public function test_a_winner_photo_gets_a_720px_copy(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $winner = Winner::create(['display_name' => 'Sam W', 'prize' => 'A car']);
        $image = $this->actingAs($admin)->post("/api/admin/winners/{$winner->id}/image", ['image' => UploadedFile::fake()->image('w.jpg', 1440, 1080)])
            ->assertOk()->json('image');
        $this->assertHasSmallCopy($image, 540);
    }
}
