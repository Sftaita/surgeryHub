<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lot 6 (D-100, §25) — EncodingAnomalyReportService::resolve() relit le signalement
 * (sous verrou pessimiste, après refresh()) et constate qu'il n'est plus OPEN. Couvre
 * une seconde tentative de résolution ET une tentative concurrente qui perd la course
 * sous le verrou — un seul type d'exception pour un seul fait métier ("ce signalement
 * a déjà été traité"), jamais deux événements ENCODING_ANOMALY_RESOLVED incohérents.
 * Mappée à error.code = 'ENCODING_ANOMALY_REPORT_ALREADY_RESOLVED' (409).
 */
class EncodingAnomalyReportAlreadyResolvedException extends ConflictHttpException
{
}
