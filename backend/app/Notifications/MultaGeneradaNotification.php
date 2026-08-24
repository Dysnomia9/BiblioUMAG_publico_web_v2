<?php

namespace App\Notifications;

use App\Models\Prestamo;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Se envía cuando PrestamoController::devolver() calcula una multa por atraso.
 * No es queued (ver nota en ReservaListaParaRetirarNotification — no hay
 * queue:work en este proyecto, se manda en línea).
 */
class MultaGeneradaNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly Prestamo $prestamo)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $monto = number_format((float) $this->prestamo->multa_monto, 0, ',', '.');

        return (new MailMessage)
            ->subject('Multa generada por atraso — Biblioteca UMAG')
            ->greeting("Hola {$notifiable->nombre},")
            ->line("Se generó una multa de \${$monto} por la devolución atrasada de \"{$this->prestamo->libro_titulo}\".")
            ->line('Puedes pagarla y consultar tu estado de deudas en el mesón de la biblioteca.')
            ->salutation('Saludos,<br>Biblioteca UMAG');
    }
}
