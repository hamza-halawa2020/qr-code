<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $guarded = [];
    protected $hidden = ['board_token_hash'];

    protected function casts(): array
    {
        return ['is_open' => 'boolean', 'automatic_queue' => 'boolean'];
    }

    public function counters(): HasMany
    {
        return $this->hasMany(Counter::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(BranchService::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function acceptsBookings(): bool
    {
        $time = now($this->timezone)->format('H:i');

        return $this->is_open && $time >= $this->opens_at && $time < $this->closes_at;
    }
}
