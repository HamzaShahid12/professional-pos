<?php

namespace App\Policies;

class BrandPolicy extends PermissionPolicy
{
    protected string $viewPermission = '';

    protected string $createPermission = 'brands.manage';

    protected string $updatePermission = 'brands.manage';

    protected string $deletePermission = 'brands.manage';
}
