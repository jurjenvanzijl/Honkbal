<?php

namespace App\Scoring;

use RuntimeException;

/**
 * Wordt gegooid wanneer een gebeurtenis niet past bij de huidige wedstrijdsituatie.
 * De melding is bedoeld voor de scorer en dus in het Nederlands.
 */
class InvalidPlayException extends RuntimeException
{
}
