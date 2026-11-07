<?php

namespace App\Security\Google;

/** D-157 — Google's tokeninfo endpoint could not be reached (transient, the client may retry). */
final class GoogleUnavailableException extends \RuntimeException
{
}
