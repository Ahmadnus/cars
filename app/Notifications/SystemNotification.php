<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One parameterised notification covering every system event.
 *
 * The event is identified by $type rather than by a separate class per event:
 * the payload shape is uniform, the in-app feed renders them all the same way,
 * and adding an event means adding a NotificationService method, not a class.
 */
class SystemNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $type    e.g. appointment.booked
     * @param  array<string, mixed>  $payload  extra data for the UI
     * @param  string|null  $url  dashboard link the notification points at
     * @param  array<int, string>  $channels  delivery channels for this send
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $body,
        public array $payload = [],
        public ?string $url = null,
        public string $level = 'info',
        protected array $channels = ['database'],
    ) {
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'level' => $this->level,
            'payload' => $this->payload,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting($this->title)
            ->line($this->body);

        if ($this->url) {
            $mail->action('عرض التفاصيل', url($this->url));
        }

        return $mail;
    }
}
