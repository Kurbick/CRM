<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyContact extends Model
{
    protected $fillable = [
        'company_id', 'first_name', 'last_name',
        'position', 'phone', 'email', 'role', 'comment',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}