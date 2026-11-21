<?php

namespace App\Http\Controllers\Web;

class CustomerController extends PartyController
{
    protected function type(): string
    {
        return 'customer';
    }
}
