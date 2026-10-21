<?php

namespace App\Policies;

use App\Models\Purchase;
use App\Models\User;

class PurchasePolicy
{
    public function view(User $user, Purchase $purchase): bool
    {
        return $purchase->card->user_id === $user->id;
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return $purchase->card->user_id === $user->id;
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $purchase->card->user_id === $user->id;
    }
}
