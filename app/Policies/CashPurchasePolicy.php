<?php

namespace App\Policies;

use App\Models\CashPurchase;
use App\Models\User;

class CashPurchasePolicy
{
    public function update(User $user, CashPurchase $cashPurchase): bool
    {
        return $cashPurchase->card->user_id === $user->id;
    }
}
