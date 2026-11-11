<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    protected $fillable = ['user_id', 'amount_pence', 'reason', 'order_id', 'added_by'];
}
