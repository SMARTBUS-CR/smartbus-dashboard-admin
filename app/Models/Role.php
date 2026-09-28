<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Spatie\Permission\Models\Role as SpatieRole;

#[Connection('mysql')]
class Role extends SpatieRole
{
    //
}
