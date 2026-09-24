<?php

namespace Modules\FreshdeskImport\Services;

/** Freshdesk answered 429 Too Many Requests: wait $retryAfter seconds before the next call. */
class RateLimited extends \Exception
{
    public $retryAfter;

    public function __construct($retryAfter)
    {
        $this->retryAfter = (int)$retryAfter;
        parent::__construct('Freshdesk rate limit reached, pausing '.$this->retryAfter.' s');
    }
}
