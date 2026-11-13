<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Spatie\Permission\Models\Permission as SpatiePermission;

#[Connection('mysql')]
class Permission extends SpatiePermission
{
    //
}
