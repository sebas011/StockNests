<?php

namespace App\Listeners;

use App\Events\WorkflowChanged;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Support\WorkflowVisibility;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyWorkflowParticipants implements ShouldQueue
{
    public int $tries = 3;

    public function handle(WorkflowChanged $event): void
    {
        $data = ['funding_source_id' => $event->fundingSourceId, 'record_type' => $event->recordType, 'record_id' => $event->recordId];
        User::query()->where('is_active', true)
            ->whereHas('fundingSources', fn ($q) => $q->whereKey($event->fundingSourceId))
            ->where('id', '!=', $event->actorId)
            ->each(function (User $user) use ($event, $data) {
                if (WorkflowVisibility::canSee($user, $data)) {
                    $user->notify(new WorkflowNotification($event));
                }
            });
    }
}
