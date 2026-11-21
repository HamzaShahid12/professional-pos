<?php

namespace App\Policies;

class WarehousePolicy extends PermissionPolicy
{
    protected string $viewPermission = '';

    protected string $createPermission = 'warehouses.manage';

    protected string $updatePermission = 'warehouses.manage';

    protected string $deletePermission = 'warehouses.manage';
}
