<?php

namespace Tests\Feature;

use App\Models\IdVerification;
use App\Models\User;
use App\Models\Winner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Privacy policy: "Winner ID documents are deleted within 30 days of the prize being handed over."
 * wds:delete-winner-ids (hourly) deletes the files once every prize the customer won has been marked
 * "Delivered", the last one at least 30 days ago.
 */
class WinnerIdDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function winnerWithId(): array
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Storage::disk('local')->put("verifications/{$user->id}/doc.jpg", 'doc');
        Storage::disk('local')->put("verifications/{$user->id}/selfie.jpg", 'selfie');
        $v = IdVerification::create(['user_id' => $user->id, 'document_path' => "verifications/{$user->id}/doc.jpg",
            'selfie_path' => "verifications/{$user->id}/selfie.jpg", 'status' => 'approved']);
        $w = Winner::create(['user_id' => $user->id, 'display_name' => 'Sam T', 'prize' => 'BMW', 'drawn_at' => now()->subDays(40)]);

        return [$user, $v, $w];
    }

    public function test_marking_delivered_records_when_and_moving_back_clears_it(): void
    {
        [, , $w] = $this->winnerWithId();
        $this->assertNull($w->handed_over_at);
        $w->update(['handover' => 'delivered']);
        $this->assertNotNull($w->fresh()->handed_over_at);
        $w->update(['handover' => 'booked']);
        $this->assertNull($w->fresh()->handed_over_at);
    }

    public function test_files_are_kept_before_30_days_and_deleted_after(): void
    {
        [$user, $v, $w] = $this->winnerWithId();
        $w->update(['handover' => 'delivered']);

        $this->travel(29)->days();
        $this->artisan('wds:delete-winner-ids')->assertSuccessful();
        Storage::disk('local')->assertExists("verifications/{$user->id}/doc.jpg");
        $this->assertNull($v->fresh()->files_deleted_at);

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->artisan('wds:delete-winner-ids')->assertSuccessful();
        Storage::disk('local')->assertMissing("verifications/{$user->id}/doc.jpg");
        Storage::disk('local')->assertMissing("verifications/{$user->id}/selfie.jpg");
        $v = $v->fresh();
        $this->assertNotNull($v->files_deleted_at);
        $this->assertSame(['', '', 'approved'], [$v->document_path, $v->selfie_path, $v->status], 'paths cleared, the record kept');
    }

    public function test_files_are_kept_while_a_prize_is_not_handed_over(): void
    {
        [$user, $v] = $this->winnerWithId();
        $this->travel(60)->days();
        $this->artisan('wds:delete-winner-ids')->assertSuccessful();
        Storage::disk('local')->assertExists("verifications/{$user->id}/doc.jpg");

        // A second win still to hand over keeps the ID even after the first prize was delivered long ago.
        Winner::where('user_id', $user->id)->first()->update(['handover' => 'delivered']);
        Winner::create(['user_id' => $user->id, 'display_name' => 'Sam T', 'prize' => 'Coilovers', 'drawn_at' => now()]);
        $this->travel(31)->days();
        $this->artisan('wds:delete-winner-ids')->assertSuccessful();
        Storage::disk('local')->assertExists("verifications/{$user->id}/doc.jpg");
        $this->assertNull($v->fresh()->files_deleted_at);
    }

    public function test_admin_gets_410_for_deleted_files(): void
    {
        [$user, $v, $w] = $this->winnerWithId();
        $admin = User::factory()->create(['role' => 'admin']);
        $w->update(['handover' => 'delivered']);
        $this->actingAs($admin)->get("/api/admin/verifications/{$v->id}/document")->assertOk();
        $this->travel(31)->days();
        $this->artisan('wds:delete-winner-ids')->assertSuccessful();
        $this->actingAs($admin)->get("/api/admin/verifications/{$v->id}/document")->assertStatus(410);
    }

    public function test_it_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('wds:delete-winner-ids')->assertSuccessful();
    }
}
