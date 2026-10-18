<?php

namespace App\Notifications;

use App\Events\WorkflowChanged;
use App\Models\User;
use App\Support\WorkflowVisibility;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(public WorkflowChanged $event) {}

    /** @return list<'database'|'mail'> */
    public function via(object $notifiable): array
    {
        return config('stocknest.notification_email') ? ['database', 'mail'] : ['database'];
    }

    /**
     * @param  User  $notifiable
     * @return array{funding_source_id: int, record_type: string, record_id: int, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'funding_source_id' => $this->event->fundingSourceId,
            'record_type' => $this->event->recordType,
            'record_id' => $this->event->recordId,
            'title' => $this->event->reference.' · '.str_replace(['.', '_'], ' ', $this->event->action),
            'url' => WorkflowVisibility::destination($notifiable, $this->event->recordType),
        ];
    }

    /** @param User $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('StockNest workflow update')
            ->line($this->toArray($notifiable)['title'])->action('Open StockNest', url()->to($this->toArray($notifiable)['url']));
    }
}
