<?php

namespace App\Http\Responses;

use App\User;

class UserTokenResponse
{
    public function __construct(User $user)
    {
        $this->token = $user->token->token;
        $this->email = $user->email;
        $this->preferred_language = $user->preferred_language;
        $this->is_active = (boolean)$user->is_active;
    }
}
