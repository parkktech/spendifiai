<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Active       = 'active';
    case Error        = 'error';
    case Disconnected = 'disconnected';
    case Pending      = 'pending';
}
