<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Sandbox extends Model
{
    protected $guarded = ['*'];
    protected function casts(): array { return ['generation' => 'integer', 'reset_at' => 'datetime']; }
}
