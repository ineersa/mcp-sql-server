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
        $decoded = json_decode($data, true);
        if (\JSON_ERROR_NONE === json_last_error()) {
            $this->logger->info('Sending immediate response', ['message' => $decoded]);
        } else {
            $this->logger->info('Sending immediate response', ['message' => $data]);
        }
        parent::send($data, $context);
    }

    protected function getOutgoingMessages(?Uuid $sessionId): array
    {
        $messages = parent::getOutgoingMessages($sessionId);

        foreach ($messages as $message) {
            $decoded = json_decode($message['message'], true);
            if (\JSON_ERROR_NONE === json_last_error()) {
                $this->logger->info('Sending queued response', ['message' => $decoded]);
            } else {
                $this->logger->info('Sending queued response', ['message' => $message['message']]);
            }
        }

        return $messages;
    }
}
