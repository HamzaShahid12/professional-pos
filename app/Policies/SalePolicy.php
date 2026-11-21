<?php

namespace App\Policies;

class SalePolicy extends PermissionPolicy
{
    protected string $viewPermission = 'sales.view';

    protected string $createPermission = 'sales.create';

    protected string $updatePermission = 'sales.update';

    protected string $deletePermission = 'sales.cancel';
}
