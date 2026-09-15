<?php

namespace App\Exceptions\Mail;

use RuntimeException;

/**
 * Thrown when an organization has no usable outbound email configuration.
 * Callers must present this as a distinct, actionable state — never as a
 * generic send failure — since the correct next step is to configure email,
 * not to retry.
 */
class OrganizationMailNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Configuration e-mail requise. Configurez une adresse d\'expédition avant d\'envoyer des documents.');
    }
}
