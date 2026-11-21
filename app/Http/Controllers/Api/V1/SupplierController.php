<?php

namespace App\Http\Controllers\Api\V1;

class SupplierController extends PartyApiController
{
    protected function type(): string
    {
        return 'supplier';
    }
}
