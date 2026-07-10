<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceTypeItem extends Model
{
    protected $fillable = [
        'service_type_id', 'name', 'price',
    ];

    public function serviceType()
    {
        return $this->belongsTo(ServiceType::class);
    }
}