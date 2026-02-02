<?php

declare(strict_types=1);

/*
 * This file is part of Ymir Laravel Bridge.
 *
 * (c) Carl Alexander <support@ymirapp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ymir\Bridge\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\SqsQueue;
use Illuminate\Queue\WorkerOptions;
use Ymir\Bridge\Laravel\Queue\SqsJob;
use Ymir\Bridge\Laravel\Queue\Worker;

class QueueWorkCommand extends Command
{
    /**
     * The console command description.
     */
    protected $description = 'Process a SQS job';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     */
    protected $hidden = true;
    /**
     * The console command name.
     */
    protected $signature = 'ymir:queue:work
                            {--connection=sqs : The name of the queue connection}
                            {--message= : The base64 encoded SQS message record}
                            {--delay=0 : The number of seconds to delay failed jobs}
                            {--timeout=0 : The number of seconds a child process can run}
                            {--tries=0 : Number of times to attempt a job before logging it failed}
                            {--force : Force the worker to run even in maintenance mode}';

    /**
     * The queue worker instance.
     *
     * @var Worker
     */
    protected $worker;

    /**
     * Constructor.
     */
    public function __construct(Worker $worker)
    {
        parent::__construct();

        $this->worker = $worker;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->laravel->isDownForMaintenance() && !$this->option('force')) {
            return self::SUCCESS;
        }

        $connectionName = $this->option('connection');
        $message = $this->option('message');

        if (empty($message) || !is_string($message)) {
            $this->error('The "--message" option is required');

            return self::FAILURE;
        }

        $message = $this->decodeMessage($message);

        if (empty($message)) {
            $this->error('Unable to decode the SQS message');

            return self::FAILURE;
        }

        $connection = $this->laravel['queue']->connection($connectionName);

        if (!$connection instanceof SqsQueue) {
            $this->error(sprintf('Connection [%s] must be an SQS connection', $connectionName));

            return self::FAILURE;
        }

        $queueUrl = $this->resolveQueueUrl($message);

        if (empty($queueUrl)) {
            $this->error('Unable to resolve queue URL');

            return self::FAILURE;
        }

        $this->worker->runSqsJob(
            new SqsJob($this->laravel, $connection->getSqs(), $this->normalizeMessage($message), $connectionName, $queueUrl),
            $connectionName,
            $this->gatherWorkerOptions()
        );

        return self::SUCCESS;
    }

    /**
     * Decode the base64 encoded SQS message.
     */
    protected function decodeMessage(string $message): ?array
    {
        $message = base64_decode($message);

        if (!is_string($message)) {
            return null;
        }

        $message = json_decode($message, true);

        return is_array($message) ? $message : null;
    }

    /**
     * Gather all the queue worker options as a single object.
     */
    protected function gatherWorkerOptions(): WorkerOptions
    {
        $options = [
            $this->option('delay'),
            $memory = 0,
            $this->option('timeout'),
            $sleep = 0,
            $this->option('tries'),
            $this->option('force'),
            $stopWhenEmpty = false,
        ];

        if (property_exists(WorkerOptions::class, 'name')) {
            $options = array_merge(['default'], $options);
        }

        return new WorkerOptions(...$options);
    }

    /**
     * Normalize the SQS message record for the Laravel SQS job.
     */
    protected function normalizeMessage(array $message): array
    {
        return [
            'MessageId' => $message['messageId'],
            'ReceiptHandle' => $message['receiptHandle'],
            'Body' => $message['body'],
            'Attributes' => $message['attributes'],
            'MessageAttributes' => $message['messageAttributes'],
        ];
    }

    /**
     * Resolve the queue URL from the given message.
     */
    protected function resolveQueueUrl(array $message): ?string
    {
        if (empty($message['eventSourceARN'])) {
            return null;
        }

        $eventSourceArn = explode(':', $message['eventSourceARN']);

        if (!isset($eventSourceArn[3], $eventSourceArn[4], $eventSourceArn[5])) {
            return null;
        }

        return sprintf(
            'https://sqs.%s.amazonaws.com/%s/%s',
            $region = $eventSourceArn[3],
            $accountId = $eventSourceArn[4],
            $queue = $eventSourceArn[5]
        );
    }
}
