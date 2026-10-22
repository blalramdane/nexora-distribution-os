<?php

namespace App\Console\Commands;

use App\Services\Outbox\MessageOutboxProcessor;
use Illuminate\Console\Command;

class ProcessMessageOutbox extends Command
{
    protected $signature = 'nexora:outbox:work {--limit=100 : Maximum number of due messages to claim in this pass}';

    protected $description = 'Deliver due transactional outbox events and persist retry/attempt history';

    public function handle(MessageOutboxProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The outbox batch limit must be an integer between 1 and 500.');
            return self::INVALID;
        }

        $result = $processor->processBatch($limit);
        $this->info(sprintf(
            'Outbox batch finished: claimed=%d sent=%d retried=%d failed=%d',
            $result['claimed'], $result['sent'], $result['retried'], $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
