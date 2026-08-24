<?php

namespace App\Notifications;

use App\Models\ReservaLibro;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Se envía cuando ReservaLibroService::liberarLibro() promueve a alguien de la cola
 * de espera a 'pendiente' (le tocó el turno). No es queued a propósito: no hay un
 * proceso `queue:work` corriendo en docker-compose.yml, así que ShouldQueue dejaría
 * esto sin enviar nunca — se manda en línea, dentro del mismo request que libera el
 * ejemplar.
 */
class ReservaListaParaRetirarNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly ReservaLibro $reserva)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $titulo = $this->reserva->libro?->titulo ?? 'el libro que reservaste';
        $fechaRetiro = $this->reserva->fecha_retiro?->translatedFormat('d/m/Y') ?? 'próximamente';

        return (new MailMessage)
            ->subject('Tu libro ya está disponible para retiro — Biblioteca UMAG')
            ->greeting("Hola {$notifiable->nombre},")
            ->line("El libro \"{$titulo}\" que tenías en lista de espera ya está disponible.")
            ->line("Tienes hasta el {$fechaRetiro} para pasar a retirarlo por biblioteca.")
            ->line('Si no lo retiras dentro de ese plazo, tu reserva puede perderse y pasar a la siguiente persona en la fila.')
            ->salutation('Saludos,<br>Biblioteca UMAG');
    }
}
