<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_the_landing_page_shows_no_placeholder_content(): void
    {
        $response = $this->get('/');

        $response->assertSee('From lesson to mastery');
        $response->assertDontSee('Jamie R.');
        $response->assertDontSee('Average rating');
        $response->assertDontSee('Joined by 110');
    }
}
