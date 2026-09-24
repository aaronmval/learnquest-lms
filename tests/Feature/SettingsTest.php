<?php

namespace Tests\Feature;

use App\Models\ClassRoom;
use App\Models\User;
use App\Notifications\ClassPostPublished;
use App\Notifications\StudentJoinedClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_return_role_specific_preferences_defaulting_on(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $professor = User::factory()->create(['role' => 'professor']);

        $this->actingAs($student)->getJson('/settings')
            ->assertOk()
            ->assertJsonPath('email', $student->email)
            ->assertJsonPath('avatar_url', null)
            ->assertExactJson([
                'name' => $student->name,
                'email' => $student->email,
                'role' => 'student',
                'avatar_url' => null,
                'notification_preferences' => ['announcements' => true, 'lessons' => true, 'mastery' => true, 'sound' => true],
            ]);

        $this->actingAs($professor)->getJson('/settings')
            ->assertOk()
            ->assertJsonPath('notification_preferences', [
                'enrollment' => true, 'at_risk' => true, 'quiz_feedback' => true, 'sound' => true,
            ]);
    }

    public function test_profile_name_updates_and_is_validated(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $this->actingAs($user)->putJson('/settings/profile', ['name' => '  Maria Santos  '])
            ->assertOk()
            ->assertJsonPath('name', 'Maria Santos');
        $this->assertSame('Maria Santos', $user->fresh()->name);

        $this->actingAs($user)->putJson('/settings/profile', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create(['role' => 'student', 'password' => 'old-password']);

        $this->actingAs($user)->putJson('/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));

        $this->actingAs($user)->putJson('/settings/password', [
            'current_password' => 'old-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->actingAs($user)->putJson('/settings/password', [
            'current_password' => 'old-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
    }

    public function test_notification_preferences_reject_keys_from_other_roles(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)->putJson('/settings/notifications', ['lessons' => false])
            ->assertOk()
            ->assertJsonPath('notification_preferences.lessons', false)
            ->assertJsonPath('notification_preferences.announcements', true);

        $this->actingAs($student)->putJson('/settings/notifications', ['enrollment' => false])->assertStatus(422);
        $this->actingAs($student)->putJson('/settings/notifications', ['lessons' => 'maybe'])->assertStatus(422);
    }

    public function test_disabled_preferences_stop_those_alerts(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);
        $classId = $this->actingAs($professor)->postJson('/professor/classes', ['name' => 'Chemistry'])->json('id');

        // Professor turns off enrollment alerts before the student joins.
        $this->actingAs($professor)->putJson('/settings/notifications', ['enrollment' => false])->assertOk();
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => ClassRoom::find($classId)->code])
            ->assertCreated();
        $this->assertSame(0, $professor->notifications()->where('type', StudentJoinedClass::class)->count());

        // Student turns off lesson alerts but keeps announcements.
        $this->actingAs($student)->putJson('/settings/notifications', ['lessons' => false])->assertOk();
        foreach (['lesson' => 'Mole Concept', 'announcement' => 'Lab Friday'] as $type => $title) {
            $this->actingAs($professor)->postJson("/professor/classes/{$classId}/posts", [
                'type' => $type, 'quarter' => '1st Quarter', 'title' => $title,
            ])->assertCreated();
        }

        $alerts = $student->notifications()->where('type', ClassPostPublished::class)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('New announcement', $alerts[0]->data['title']);
    }

    public function test_avatar_upload_view_and_delete(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'student']);

        $url = $this->actingAs($user)->post('/settings/avatar', [
            'photo' => UploadedFile::fake()->image('me.png', 200, 200),
        ], ['Accept' => 'application/json'])->assertOk()->json('avatar_url');

        $this->assertNotNull($url);
        $path = $user->fresh()->avatar_path;
        Storage::disk('local')->assertExists($path);
        $this->actingAs($user)->get('/settings/avatar')->assertOk();

        // Replacing deletes the old file.
        $this->actingAs($user)->post('/settings/avatar', [
            'photo' => UploadedFile::fake()->image('new.jpg', 100, 100),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($path);

        $this->actingAs($user)->deleteJson('/settings/avatar')->assertOk()->assertJsonPath('avatar_url', null);
        $this->assertNull($user->fresh()->avatar_path);
        $this->actingAs($user)->get('/settings/avatar')->assertNotFound();
    }

    public function test_avatar_rejects_non_images_and_is_private_per_user(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);

        $this->actingAs($owner)->post('/settings/avatar', [
            'photo' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->actingAs($owner)->post('/settings/avatar', [
            'photo' => UploadedFile::fake()->image('me.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        // /settings/avatar only ever serves the signed-in user's own photo.
        $this->actingAs($other)->get('/settings/avatar')->assertNotFound();
    }

    public function test_shell_passes_avatar_and_sound_setting_to_the_navbar(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'student']);

        $this->actingAs($user)->get('/student/dashboard')
            ->assertOk()
            ->assertSee('avatarUrl: null', false)
            ->assertSee('alertSound: true', false);

        $this->actingAs($user)->put('/settings/notifications', ['sound' => false], ['Accept' => 'application/json']);
        $this->actingAs($user)->post('/settings/avatar', [
            'photo' => UploadedFile::fake()->image('me.png'),
        ], ['Accept' => 'application/json']);

        $this->actingAs($user)->get('/student/dashboard')
            ->assertOk()
            ->assertSee('alertSound: false', false)
            // @json escapes forward slashes in the shell's inline config.
            ->assertSee('\/settings\/avatar?v=', false);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/settings')->assertUnauthorized();
        $this->putJson('/settings/profile', ['name' => 'X'])->assertUnauthorized();
    }
}
