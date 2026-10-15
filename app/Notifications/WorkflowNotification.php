<?php

namespace App\Notifications;

use App\Events\WorkflowChanged;
use App\Support\WorkflowVisibility;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(public WorkflowChanged $event) {}

    public function via(object $notifiable): array
    {
        return config('stocknest.notification_email') ? ['database', 'mail'] : ['database'];
    }

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

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('StockNest workflow update')
            ->line($this->toArray($notifiable)['title'])->action('Open StockNest', url($this->toArray($notifiable)['url']));
    }
}
