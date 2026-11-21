<?php

namespace App\Policies;

class SupplierPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'suppliers.manage';

    protected string $createPermission = 'suppliers.manage';

    protected string $updatePermission = 'suppliers.manage';

    protected string $deletePermission = 'suppliers.manage';
}
