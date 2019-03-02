<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\User;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function a_user_has_a_preferredLanguage_field_with_default_de()
    {
        $this->getActivatedUser();

        $this->assertEquals('de', User::first()->preferred_language);
    }
}
