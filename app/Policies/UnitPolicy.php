<?php

namespace App\Policies;

class UnitPolicy extends PermissionPolicy
{
    protected string $viewPermission = '';

    protected string $createPermission = 'units.manage';

    protected string $updatePermission = 'units.manage';

    protected string $deletePermission = 'units.manage';
}
