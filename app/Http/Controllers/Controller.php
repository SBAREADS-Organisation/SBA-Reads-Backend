<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use App\Traits\Shared\SharedServices;
use App\Traits\Slack\SlackNotifiable;

abstract class Controller
{
    //
    use ApiResponse, SharedServices, SlackNotifiable;
    /**
     * Controller constructor.
     */
    public function __construct()
    {
        // You can initialize any common properties or services here
    }
}
