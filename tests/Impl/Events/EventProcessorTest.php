<?php

namespace LaunchDarkly\Tests\Impl\Events;

use LaunchDarkly\Impl\Events\EventProcessor;
use LaunchDarkly\LDContext;
use LaunchDarkly\Subsystems\EventPublisher;
use PHPUnit\Framework\TestCase;

class EventProcessorTest extends TestCase
{
    private function makeIdentifyEvent(string $key): array
    {
        return [
            'kind' => 'identify',
            'creationDate' => 1000000,
            'context' => LDContext::create($key),
        ];
    }

    public function testFlushPublishesEventsThatCanBeEncoded()
    {
        $publisher = $this->createMock(EventPublisher::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (string $payload): bool {
                $decoded = json_decode($payload, true);
                return count($decoded) === 1 && $decoded[0]['context']['key'] === 'good';
            }))
            ->willReturn(true);
        $processor = new EventProcessor('sdk-key', ['event_publisher' => $publisher, 'capacity' => 1000]);
        $processor->enqueue($this->makeIdentifyEvent('good'));
        $processor->enqueue($this->makeIdentifyEvent("bad\xB1"));

        $this->assertTrue($processor->flush());
    }

    public function testFlushDoesNotPublishWhenNoEventCanBeEncoded()
    {
        $publisher = $this->createMock(EventPublisher::class);
        $publisher->expects($this->never())->method('publish');
        $processor = new EventProcessor('sdk-key', ['event_publisher' => $publisher, 'capacity' => 1000]);
        $processor->enqueue($this->makeIdentifyEvent("bad\xB1"));

        $this->assertFalse($processor->flush());
    }
}
