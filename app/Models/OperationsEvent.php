<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OperationsEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['bootstrap_slot'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'created_at' => 'datetime'];
    }

    protected function performUpdate(Builder $query)
    {
        throw new \LogicException('La auditoría operativa no admite modificaciones.');
    }

    protected function performDeleteOnModel()
    {
        throw new \LogicException('La auditoría operativa no admite borrado.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new \LogicException('La auditoría operativa no admite modificaciones.');
    }
}
