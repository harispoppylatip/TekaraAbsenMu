<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnrollmentSession extends Model
{
    protected $fillable = ['device_id', 'step', 'temp_template_1', 'temp_template_2', 'status'];
}
