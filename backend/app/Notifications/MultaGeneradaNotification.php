<?php

namespace App\Notifications;

use App\Models\Multa;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Se envía cuando PrestamoController::devolver() genera una multa por atraso.
 * No es queued (ver nota en ReservaListaParaRetirarNotification — no hay
 * queue:work en este proyecto, se manda en línea).
 */
class MultaGeneradaNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Multa $multa)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $monto = number_format((float) $this->multa->monto, 0, ',', '.');
        $titulo = $this->multa->prestamo?->libro_titulo;

        return (new MailMessage)
            ->subject('Multa generada por atraso — Biblioteca UMAG')
            ->greeting("Hola {$notifiable->nombre},")
            ->line($titulo
                ? "Se generó una multa de \${$monto} por la devolución atrasada de \"{$titulo}\"."
                : "Se generó una multa de \${$monto} a tu nombre.")
            ->line('Puedes pagarla y consultar tu estado de deudas en el mesón de la biblioteca.')
            ->salutation('Saludos,<br>Biblioteca UMAG');
    }
}
