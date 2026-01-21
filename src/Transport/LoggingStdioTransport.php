<?php

declare(strict_types=1);

namespace App\Transport;

use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class LoggingStdioTransport extends StdioTransport
{
    public function __construct(
        $input = \STDIN,
        $output = \STDOUT,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($input, $output, $logger);
    }

    public function send(string $data, array $context): void
    {
        $this->logger->info('Sending immediate response', ['data' => $data, 'context' => $context]);
        parent::send($data, $context);
    }

    protected function getOutgoingMessages(?Uuid $sessionId): array
    {
        $messages = parent::getOutgoingMessages($sessionId);

        foreach ($messages as $message) {
            $this->logger->info('Sending queued response', ['message' => $message]);
        }

        return $messages;
    }
}
