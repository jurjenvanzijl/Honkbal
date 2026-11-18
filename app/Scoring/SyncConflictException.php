<?php

namespace App\Scoring;

use RuntimeException;

/**
 * De offline-wachtrij van een scorer past niet meer op wat er op de server staat
 * (bijvoorbeeld omdat een tweede scorer tegelijk heeft ingevoerd).
 */
class SyncConflictException extends RuntimeException
{
}
