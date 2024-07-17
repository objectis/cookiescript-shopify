<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScriptSrc extends Model
{
    use HasFactory;

    protected $table = 'script_src';

    protected $fillable = ['shop_domain', 'src'];
}