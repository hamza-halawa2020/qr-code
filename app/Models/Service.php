<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $guarded = [];

    public function branchServices()
    {
        return $this->hasMany(BranchService::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_services')->withPivot('is_active')->withTimestamps();
    }
}
