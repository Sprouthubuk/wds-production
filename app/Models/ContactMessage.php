<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'topic', 'phone', 'order_reference', 'message', 'page', 'handled_at'];
}
