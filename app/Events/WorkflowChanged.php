<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class WorkflowChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $fundingSourceId,
        public int $actorId,
        public string $action,
        public string $recordType,
        public int $recordId,
        public string $reference,
    ) {}
}
