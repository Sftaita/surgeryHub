<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Lot 5 (D-068) — la Firm référencée comme primaryFirm existe mais active=false.
 * Mappée à error.code = 'PRIMARY_FIRM_INACTIVE' par ApiExceptionSubscriber.
 */
class PrimaryFirmInactiveException extends UnprocessableEntityHttpException
{
}
