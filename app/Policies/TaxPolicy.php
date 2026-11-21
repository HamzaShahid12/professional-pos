<?php

namespace App\Policies;

class TaxPolicy extends PermissionPolicy
{
    protected string $viewPermission = '';

    protected string $createPermission = 'taxes.manage';

    protected string $updatePermission = 'taxes.manage';

    protected string $deletePermission = 'taxes.manage';
}
