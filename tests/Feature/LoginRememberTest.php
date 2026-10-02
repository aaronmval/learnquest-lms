<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginRememberTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider tickedValues */
    #[\PHPUnit\Framework\Attributes\DataProvider('tickedValues')]
    public function test_ticking_remember_me_signs_in_and_sets_the_remember_cookie(string $value): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => $value]);

        $response->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($user);
        $response->assertCookie(Auth::guard()->getRecallerName());
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public static function tickedValues(): array
    {
        // "on" is what a checkbox with no value attribute sends.
        return ['value 1' => ['1'], 'value on' => ['on']];
    }

    public function test_without_remember_me_no_remember_cookie_is_set(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($user);
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
    }

    public function test_the_box_stays_ticked_after_a_wrong_password(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong', 'remember' => '1'])
            ->assertSessionHasErrorsIn('login', 'email')
            ->assertSessionHasInput('remember', true);

        $this->assertGuest();
    }
}
