<?php

namespace App\Policies;

class CustomerPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'customers.manage';

    protected string $createPermission = 'customers.manage';

    protected string $updatePermission = 'customers.manage';

    protected string $deletePermission = 'customers.manage';
}
