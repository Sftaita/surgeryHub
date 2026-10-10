<?php

namespace App\Enum;

/** D-140 — application depuis laquelle une association MedVue a été révoquée. */
enum MedVueLinkRevocationSource: string
{
    case SURGICALHUB = 'SURGICALHUB';
    case MEDVUE      = 'MEDVUE';
}
