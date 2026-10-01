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
                'general_preferences' => [
                    'theme' => null,
                    'motion' => 'full',
                    'text_size' => 'default',
                    'sidebar' => 'remember',
                    'start_page' => 'home',
                    'restore_last_page' => true,
                    'chart_labels' => true,
                    'dashboard_lock' => true,
                ],
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

    public function test_other_signed_in_users_see_a_professors_photo_beside_their_name(): void
    {
        Storage::fake('local');
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $subjectId = $this->actingAs($professor)->postJson('/professor/subjects', ['name' => 'Chemistry'])->json('id');
        $class = $this->actingAs($professor)->postJson("/professor/subjects/{$subjectId}/sections", [
            'name' => 'Class A',
            'section' => 'STEM A',
        ])->json();
        $this->actingAs($professor)->postJson("/professor/classes/{$class['id']}/posts", [
            'type' => 'announcement',
            'quarter' => '1st Quarter',
            'title' => 'Welcome',
        ])->assertCreated();
        $this->actingAs($student)->postJson('/student/classes/join', ['code' => $class['code']])->assertCreated();

        // No photo yet: initials are used.
        $this->actingAs($student)->getJson("/student/classes/{$class['id']}")->assertJsonPath('professor.avatar_url', null);
        $this->actingAs($student)->get("/avatars/{$professor->id}")->assertNotFound();

        $this->actingAs($professor)->post('/settings/avatar', [
            'photo' => UploadedFile::fake()->image('me.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $url = $this->actingAs($student)->getJson("/student/classes/{$class['id']}")->assertOk()->json('professor.avatar_url');
        $this->assertStringContainsString("/avatars/{$professor->id}?v=", $url);
        $this->actingAs($student)->get($url)->assertOk();

        $this->actingAs($student)->getJson('/student/classes')->assertJsonPath('0.professor.avatar_url', $url);
        $this->actingAs($student)->getJson("/student/classes/{$class['id']}/posts")
            ->assertJsonPath('0.author.avatar_url', $url)
            ->assertJsonMissingPath('0.author.avatar_path');
        $this->actingAs($professor)->getJson("/professor/classes/{$class['id']}/posts")->assertJsonPath('0.author.avatar_url', $url);
        $this->actingAs($professor)->getJson('/professor/subjects')->assertJsonPath('0.owner.avatar_url', $url);

        // Guests can't fetch it.
        $this->post('/logout');
        $this->get("/avatars/{$professor->id}")->assertRedirect('/login');
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

    public function test_general_settings_are_saved_and_validated(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)->putJson('/settings/general', [
            'theme' => 'dark',
            'text_size' => 'large',
            'restore_last_page' => false,
        ])
            ->assertOk()
            ->assertJsonPath('general_preferences.theme', 'dark')
            ->assertJsonPath('general_preferences.text_size', 'large')
            ->assertJsonPath('general_preferences.restore_last_page', false)
            // Settings that were not sent keep their defaults.
            ->assertJsonPath('general_preferences.motion', 'full');

        // A later partial save keeps the earlier choices.
        $this->actingAs($student)->putJson('/settings/general', ['motion' => 'reduced'])
            ->assertOk()
            ->assertJsonPath('general_preferences.theme', 'dark')
            ->assertJsonPath('general_preferences.motion', 'reduced');

        $this->actingAs($student)->putJson('/settings/general', ['theme' => 'neon'])
            ->assertStatus(422)->assertJsonValidationErrors('theme');
        $this->actingAs($student)->putJson('/settings/general', ['font' => 'comic'])
            ->assertStatus(422);
        $this->assertSame('dark', $student->fresh()->generalPreferences()['theme']);
    }

    public function test_presentation_defaults_are_professor_only(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($professor)->getJson('/settings')
            ->assertJsonPath('general_preferences.deck_slide_count', 12)
            ->assertJsonPath('general_preferences.deck_theme', 'learnquest')
            ->assertJsonPath('general_preferences.quiz_setup_tour_seen', false);

        $this->actingAs($professor)->putJson('/settings/general', ['quiz_setup_tour_seen' => true])
            ->assertOk()
            ->assertJsonPath('general_preferences.quiz_setup_tour_seen', true);
        $this->actingAs($student)->putJson('/settings/general', ['quiz_setup_tour_seen' => true])->assertStatus(422);

        $this->actingAs($professor)->putJson('/settings/general', ['deck_slide_count' => 20, 'deck_theme' => 'emerald'])
            ->assertOk()
            ->assertJsonPath('general_preferences.deck_slide_count', 20)
            ->assertJsonPath('general_preferences.deck_theme', 'emerald');
        $this->actingAs($professor)->putJson('/settings/general', ['deck_slide_count' => 50])
            ->assertStatus(422)->assertJsonValidationErrors('deck_slide_count');

        $this->actingAs($student)->putJson('/settings/general', ['deck_theme' => 'emerald'])->assertStatus(422);
        $this->actingAs($student)->getJson('/settings')->assertJsonMissingPath('general_preferences.deck_theme');
    }

    public function test_general_settings_reset_to_defaults(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $this->actingAs($user)->putJson('/settings/general', ['theme' => 'dark', 'sidebar' => 'expanded', 'dashboard_lock' => false])
            ->assertOk()
            ->assertJsonPath('general_preferences.dashboard_lock', false);

        $this->actingAs($user)->deleteJson('/settings/general')
            ->assertOk()
            ->assertJsonPath('general_preferences.theme', null)
            ->assertJsonPath('general_preferences.sidebar', 'remember')
            ->assertJsonPath('general_preferences.dashboard_lock', true);
        $this->assertNull($user->fresh()->general_preferences);
    }

    public function test_shell_opens_on_the_chosen_start_page_with_preferences(): void
    {
        $professor = User::factory()->create(['role' => 'professor']);

        $this->actingAs($professor)->get('/professor/dashboard')
            ->assertOk()
            ->assertSee('\/pages\/professor\/professor-home.html', false);

        $this->actingAs($professor)->putJson('/settings/general', ['start_page' => 'dashboard', 'motion' => 'reduced'])->assertOk();

        $this->actingAs($professor)->get('/professor/dashboard')
            ->assertOk()
            ->assertSee('\/pages\/professor\/professor-dashboard.html', false)
            ->assertSee('"motion":"reduced"', false);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/settings')->assertUnauthorized();
        $this->putJson('/settings/profile', ['name' => 'X'])->assertUnauthorized();
    }
}
