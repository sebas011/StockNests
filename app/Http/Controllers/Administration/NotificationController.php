<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Support\WorkflowVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $fundIds = $request->user()->fundingSources()->pluck('funding_sources.id');
        $notifications = $request->user()->notifications()->latest()->limit(100)->get()
            ->filter(fn ($n) => $this->visible($request, $n->data));

        return Inertia::render('stocknest', [
            'page' => ['key' => 'approvals', 'title' => 'Notifications', 'subtitle' => 'Updates for your authorized funding sources.', 'columns' => [['key' => 'title', 'label' => 'Update'], ['key' => 'date', 'label' => 'Date', 'format' => 'date'], ['key' => 'status', 'label' => 'Status', 'format' => 'status']], 'rows' => $notifications->map(fn ($n) => ['id' => $n->id, 'title' => $n->data['title'], 'date' => $n->created_at->toISOString(), 'status' => $n->read_at ? 'read' : 'unread', 'actions' => [['label' => 'Open record list', 'href' => WorkflowVisibility::destination($request->user(), $n->data['record_type'])], ...($n->read_at ? [] : [['label' => 'Mark read', 'href' => '/notifications/'.$n->id.'/read', 'method' => 'post']])]])->values()],
            'funds' => $request->user()->fundingSources()->get()->map(fn ($f) => ['id' => $f->id, 'label' => $f->name]), 'filters' => [],
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        abort_unless($this->visible($request, $record->data), 403);
        $record->markAsRead();

        return back()->with('success', 'Notification marked read.');
    }

    /** @param array<string, mixed> $data */
    private function visible(Request $request, array $data): bool
    {
        return WorkflowVisibility::canSee($request->user(), $data);
    }
}
