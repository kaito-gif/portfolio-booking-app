<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` は `/admin` へリダイレクトする（詳細設計9章のルート表）。
     */
    public function test_the_application_redirects_home_to_admin(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
