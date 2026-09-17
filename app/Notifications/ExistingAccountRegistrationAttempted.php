<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the OWNER of an email address someone just tried to register with
 * (Sprint 1.1 §5) — the registration response itself never discloses that
 * the account already existed, so this is the only place that information
 * ever reaches anyone, and it only ever reaches the legitimate owner's own
 * inbox. Uses the default ('mail') channel like every other Auth
 * notification, i.e. the platform mailer, never tenant SMTP.
 */
class ExistingAccountRegistrationAttempted extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tentative d’inscription avec votre adresse email')
            ->line('Quelqu’un a tenté de créer un compte avec cette adresse email, qui est déjà associée à un compte existant.')
            ->line('Si c’était vous, connectez-vous ou réinitialisez votre mot de passe si vous l’avez oublié.')
            ->action('Se connecter', url('/login'))
            ->line('Si vous n’êtes pas à l’origine de cette tentative, aucune action n’est requise — votre compte n’a pas été modifié.');
    }
}
