<?php

namespace App\Enum;

enum ImplantSubMissionStatus: string
{
    case DRAFT = 'DRAFT';
    case INVOICED = 'INVOICED';
    case PAID = 'PAID';
}
