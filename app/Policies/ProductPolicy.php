<?php

namespace App\Policies;

class ProductPolicy extends PermissionPolicy
{
    protected string $viewPermission = 'products.view';

    protected string $createPermission = 'products.create';

    protected string $updatePermission = 'products.update';

    protected string $deletePermission = 'products.delete';
}
