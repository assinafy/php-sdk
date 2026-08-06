<?php

declare(strict_types=1);

namespace Assinafy\SDK\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Shared logger proxy used by already-created resources and the default transport.
 *
 * @internal
 */
final class MutableLogger extends AbstractLogger
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        // PSR-Log 1 accepts only strings while PSR-Log 2/3 also type Stringable.
        // Normalize at this compatibility boundary before forwarding.
        $this->logger->log($level, (string) $message, $context);
    }
}
