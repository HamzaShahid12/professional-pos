<?php

namespace App\Policies;

class PurchasePolicy extends PermissionPolicy
{
    protected string $viewPermission = 'purchases.view';

    protected string $createPermission = 'purchases.create';

    protected string $updatePermission = 'purchases.update';

    protected string $deletePermission = 'purchases.update';
}
