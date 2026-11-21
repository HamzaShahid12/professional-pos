<?php

namespace App\Http\Controllers\Api\V1;

class CustomerController extends PartyApiController
{
    protected function type(): string
    {
        return 'customer';
    }
}
