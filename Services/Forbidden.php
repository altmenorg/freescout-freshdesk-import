<?php

namespace Modules\FreshdeskImport\Services;

/** Freshdesk answered 403: the API key is valid but its agent lacks the permission (e.g. not an administrator). */
class Forbidden extends \Exception
{
}
