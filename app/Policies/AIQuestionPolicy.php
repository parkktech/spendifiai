<?php

namespace App\Policies;

use App\Models\AIQuestion;
use App\Models\User;

class AIQuestionPolicy
{
    public function view(User $user, AIQuestion $q): bool { return $user->id === $q->user_id; }
    public function answer(User $user, AIQuestion $q): bool { return $user->id === $q->user_id; }
}
