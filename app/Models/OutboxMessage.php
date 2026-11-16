<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['processed_at' => 'datetime']; }
}
