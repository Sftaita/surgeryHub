<?php

namespace App\Enum;

enum PublicationChannel: string
{
    case IN_APP = 'IN_APP';
    case PUSH = 'PUSH';
    case EMAIL = 'EMAIL';
}
