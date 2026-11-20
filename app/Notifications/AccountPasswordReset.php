<?php

namespace App\Notifications;

use App\Services\Auth\RecoveryMailer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AccountPasswordReset extends ResetPassword
{
    public function toMail($notifiable)
    {
        $url = rtrim(config('app.url'), '/').route('password.reset', [], false).'#'.http_build_query([
            'token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);

        return (new MailMessage)->mailer(app(RecoveryMailer::class)->name())
            ->subject('Restablecer contraseña | Lefenda')
            ->line('Recibimos una solicitud para restablecer tu contraseña.')
            ->action('Restablecer contraseña', $url)
            ->line('El enlace caduca en '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutos y sólo puede utilizarse una vez.')
            ->line('Si tu navegador no usa JavaScript, copia el valor token del enlace en el campo Código de recuperación.')
            ->line('Si no solicitaste este cambio, ignora este correo. Tu contraseña no ha cambiado.');
    }
}
