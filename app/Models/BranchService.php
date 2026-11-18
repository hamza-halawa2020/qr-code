<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchService extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
