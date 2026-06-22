<?php

namespace App\Enum;

enum ShiftPeriod: string
{
    case MATIN       = 'MATIN';
    case APRES_MIDI  = 'APRES_MIDI';
    case JOURNEE     = 'JOURNEE';
}
