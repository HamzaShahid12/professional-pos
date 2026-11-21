<?php

namespace App\Http\Controllers\Web;

class SupplierController extends PartyController
{
    protected function type(): string
    {
        return 'supplier';
    }
}
