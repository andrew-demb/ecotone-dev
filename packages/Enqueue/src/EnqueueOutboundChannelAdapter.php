<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalMessage;

abstract class EnqueueOutboundChannelAdapter implements MessageHandler
{
    private bool $initialized = false;

    public function __construct(
        protected CachedConnectionFactory $connectionFactory,
        protected string $queueName,
        protected bool $autoDeclare,
        protected OutboundMessageConverter $outboundMessageConverter)
    {}

    public abstract function initialize(): void;

    public function handle(Message $message): void
    {
        if ($this->autoDeclare && ! $this->initialized) {
            $this->initialize();
            $this->initialized = true;
        }

        $outboundMessage                       = $this->outboundMessageConverter->prepare($message);
        $headers                               = $outboundMessage->getHeaders();
        $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

        $messageToSend = new DbalMessage($outboundMessage->getPayload(), $headers, []);

        $this->connectionFactory->getProducer()
            ->setTimeToLive($outboundMessage->getTimeToLive())
            ->setDeliveryDelay($outboundMessage->getDeliveryDelay())
            ->send($this->connectionFactory->createContext()->createQueue($this->queueName), $messageToSend);
    }
}