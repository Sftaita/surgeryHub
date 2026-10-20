<?php

namespace App\Security\Google;

/** D-157 — the Google ID token is not acceptable; `getMessage()` is a reason code, never the token. */
final class GoogleTokenRejectedException extends \RuntimeException
{
}
