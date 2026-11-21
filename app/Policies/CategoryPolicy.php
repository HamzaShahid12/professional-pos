<?php

namespace App\Policies;

class CategoryPolicy extends PermissionPolicy
{
    protected string $viewPermission = '';

    protected string $createPermission = 'categories.manage';

    protected string $updatePermission = 'categories.manage';

    protected string $deletePermission = 'categories.manage';
}
