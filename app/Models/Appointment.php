<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'customer_phone', 'customer_name', 'customer_email'];

    protected function casts(): array
    {
        return ['customer_phone' => 'encrypted', 'customer_name' => 'encrypted', 'customer_email' => 'encrypted'];
    }

    public function slot()
    {
        return $this->belongsTo(AppointmentSlot::class, 'appointment_slot_id');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }
}
