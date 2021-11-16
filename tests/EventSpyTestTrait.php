<?php
/**
 * @author Adam Charron <adam.c@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

namespace VanillaTests;

use Garden\EventManager;
use Garden\Events\BulkUpdateEvent;
use Garden\Events\GenericResourceEvent;
use Garden\Events\ResourceEvent;
use OpenStack\Metric\v1\Gnocchi\Models\Resource;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\Constraint\IsEqual;
use PHPUnit\Framework\TestCase;
use Vanilla\Models\DirtyRecordModel;
use VanillaTests\Fixtures\SpyingEventManager;

/**
 * Trait for asserting that events are dispatched properly.
 */
trait EventSpyTestTrait {

    /** @var array Called event handler information. */
    private $calledHandlers = [];

    /** @var bool */
    private $isBound = false;

    /**
     * Cleanup the trait (e.g. reset the state of collection properties).
     */
    private function cleanupEventSpyTestTrait(): void {
        $this->clearDispatchedEvents();
        $this->clearFiredEvents();
        $this->clearCalledHandlers();
    }

    /**
     * Setup.
     */
    public function setUpEventSpyTestTrait() {
        if (!$this->isBound) {
            $this->getEventManager()->bindClass($this);
            $this->isBound = true;
        }
        $this->cleanupEventSpyTestTrait();
    }

    /**
     * Teardown.
     */
    public function tearDownEventSpyTestTrait() {
        $this->cleanupEventSpyTestTrait();
    }

    /**
     * Clear the called event handlers.
     */
    public function clearCalledHandlers() {
        $this->calledHandlers = [];
    }

    /**
     * Track a called handler.
     *
     * @param string $name
     * @param array $arguments
     */
    public function handlerCalled(string $name, array $arguments) {
        $this->calledHandlers[] = [
            'name' => $name,
            'args' => $arguments,
        ];
    }

    /**
     * Assert that an event handler was called with certain arguments.
     *
     * @param string $name The method name of the called handler.
     * @param array $contstraints Constraints to assert on called arguments. Arguments will not be checked unless this is called.
     *
     * @return array The called handler.
     */
    public function assertHandlerCalled(string $name, array $contstraints = null): array {
        $foundHandler = null;
        foreach ($this->calledHandlers as $handler) {
            $calledName = $handler['name'];
            $calledArgs = $handler['args'];

            if ($calledName !== $name) {
                continue;
            }

            if ($contstraints !== null) {
                foreach ($contstraints as $key => $argument) {
                    if (!isset($calledArgs[$key])) {
                        continue 2;
                    }
                    if ($argument instanceof Constraint) {
                        if (!$argument->evaluate($calledArgs[$key], '', true)) {
                            continue 2;
                        }
                    } elseif ($argument != $calledArgs[$key]) {
                        continue 2;
                    }
                }
            }

            $foundHandler = $handler;
        }

        $this->assertTrue(
            !!$foundHandler,
            "Could not find a matching call for $name in called handlers:\n" . json_encode($this->calledHandlers, JSON_PRETTY_PRINT)
        );

        return $foundHandler['args'];
    }

    /**
     * Clear the dispatched events.
     */
    protected function clearDispatchedEvents() {
        $this->getEventManager()->clearDispatchedEvents();
    }

    /**
     * Clear the fired events.
     */
    protected function clearFiredEvents() {
        $this->getEventManager()->clearFiredEvents();
    }

    /**
     * @return SpyingEventManager
     */
    protected function getEventManager(): SpyingEventManager {
        return \Gdn::getContainer()->get(EventManager::class);
    }

    /**
     * Assert that events were dispatched.
     *
     * @param ResourceEvent[] $events
     * @param array|string[] $matchPayloadFields
     * @param bool $strictCount If set to true we are asserting these are the only events dispatched.
     */
    public function assertEventsDispatched(array $events, array $matchPayloadFields = ["*"], bool $strictCount = false) {
        if ($strictCount) {
            $expectedCount = count($events);
            $dispatchedEvents = $this->getEventManager()->getDispatchedEvents();
            $dispatchedEvents = array_values(array_filter($dispatchedEvents, function ($event) {
                return $event instanceof ResourceEvent;
            }));
            $actualCount = count($dispatchedEvents);
            TestCase::assertEquals(
                $expectedCount,
                $actualCount,
                "Expected {$expectedCount} events to be dispatched, but $actualCount events were dispatched. Dispatched Events:\n".
                json_encode($dispatchedEvents, JSON_PRETTY_PRINT)
            );
        }
        foreach ($events as $event) {
            $this->assertEventDispatched($event, $matchPayloadFields);
        }
    }


    /**
     * Looks for a fired event & returns either true if it's found or false if it's not.
     *
     * @param string $event
     * @return bool
     */
    private function lookForEventFired(string $event): bool {
        $event = strtolower($event);
        $firedEvents = array_column($this->getEventManager()->getFiredEvents(), 0);
        $firedEvents = array_map("strtolower", $firedEvents);
        return in_array($event, $firedEvents);
    }

    /**
     * Assert an event was fired.
     *
     * @param string $event
     */
    public function assertEventFired(string $event): void {
        TestCase::assertTrue($this->lookForEventFired($event));
    }

    /**
     * Assert an event wasn't fired.
     *
     * @param string $event
     */
    public function assertEventNotFired(string $event): void {
        TestCase::assertFalse($this->lookForEventFired($event));
    }

    /**
     * Assert that no events were dispatched.
     *
     * @param string $eventClass The type of event to check.
     */
    public function assertNoEventsDispatched(string $eventClass = null) {
        $events = $this->getEventManager()->getDispatchedEvents();
        $events = array_values(array_filter($events, function ($event) {
            return $event instanceof ResourceEvent;
        }));
        if ($eventClass !== null) {
            $events = array_filter($events, function ($event) use ($eventClass) {
                return is_a($event, $eventClass);
            });
        }

        TestCase::assertEquals(0, count($events), 'No events were supposed to be dispatched.');
    }

    /**
     * Assert that a specific event was not dispatched.
     *
     * @param array $eventProperties
     */
    public function assertEventNotDispatched(array $eventProperties = []) {
        /** @var ResourceEvent[] $events */
        $events = $this->getEventManager()->getDispatchedEvents();

        $eventDispatched = false;
        if ($eventProperties) {
            foreach ($events as $event) {
                if (!($eventDispatched instanceof ResourceEvent)) {
                    continue;
                }

                $type = $eventProperties["type"] ?? '';
                $action = $eventProperties["action"] ?? '';
                if ($event->getType() === $type &&
                    $event->getAction() === $action
                ) {
                    $eventDispatched = true;
                }
            }
        }
        TestCase::assertEquals(false, $eventDispatched, 'No events were supposed to be dispatched.');
    }

    /**
     * @param BulkUpdateEvent $updateEvent
     */
    public function assertBulkEventDispatched(BulkUpdateEvent $updateEvent) {
        $hasEvent = false;
        $dispatchedEvents = $this->getEventManager()->getDispatchedEvents();
        /** @var BulkUpdateEvent $dispatchedEvent */
        foreach ($dispatchedEvents as $dispatchedEvent) {
            $constraint = new IsEqual($updateEvent);
            if ($constraint->evaluate($dispatchedEvent, '', true)) {
                $hasEvent = true;
            }
        }

        $this->assertTrue(
            $hasEvent,
            "Could not find a matching event for $updateEvent in dispatched events:\n" . json_encode($dispatchedEvents, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Assert that an event was dispatched.
     *
     * @param ResourceEvent $event
     * @param array|string[] $matchPayloadFields
     */
    public function assertEventDispatched(ResourceEvent $event, array $matchPayloadFields = ["*"]) {
        $matchAll = in_array("*", $matchPayloadFields);
        $hasEvent = false;
        $dispatchedEvents = $this->getEventManager()->getDispatchedEvents();
        /** @var ResourceEvent $dispatchedEvent */
        foreach ($dispatchedEvents as $dispatchedEvent) {
            if (!($event instanceof ResourceEvent) || !($dispatchedEvent instanceof ResourceEvent)) {
                continue;
            }

            if ($dispatchedEvent->getFullEventName() !== $event->getFullEventName()) {
                continue;
            }

            if ($matchAll) {
                $matchPayloadFields = array_keys($event->getPayload()[$event->getType()]);
            }

            foreach ($matchPayloadFields as $matchPayloadField) {
                $dispatchedField = $dispatchedEvent->getPayload()[$dispatchedEvent->getType()][$matchPayloadField] ?? null;
                $expectedField = $event->getPayload()[$event->getType()][$matchPayloadField] ?? null;
                if ($dispatchedField !== $expectedField) {
                    continue 2;
                }
            }

            $hasEvent = true;
        }

        $this->assertTrue(
            $hasEvent,
            "Could not find a matching event for $event in dispatched events:\n" . json_encode($dispatchedEvents, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Generate an expected event.
     *
     * @param string $type
     * @param string $action
     * @param array $payload
     * @return ResourceEvent
     */
    private function expectedResourceEvent(string $type, string $action, array $payload): ResourceEvent {
        if (is_a($type, ResourceEvent::class)) {
            $recordType = $type::typeFromClass();
            return new $type(
                $action,
                [$recordType => $payload],
                $this->getCurrentUser()
            );
        } else {
            $event = new GenericResourceEvent(
                $action,
                [$type => $payload],
                $this->getCurrentUser()
            );
            $event->setType($type);
            return $event;
        }
    }

    /**
     * Get the current user.
     */
    private function getCurrentUser() {
        return \Gdn::userModel()->currentFragment();
    }

    /**
     * Test a correct dirty record is inserted.
     *
     * @param string $recordType
     * @param int $recordID
     */
    public function assertDirtyRecordInserted(string $recordType, int $recordID) {
        /** @var DirtyRecordModel $dirtyRecordModel */
        $dirtyRecordModel = \Gdn::getContainer()->get(DirtyRecordModel::class);
        $record = $dirtyRecordModel->select(["recordType" => $recordType, "recordID" => $recordID]);
        $this->assertEquals($recordID, $record[0]["recordID"]);
        $this->resetTable('dirtyRecord');
    }

    /**
     * Run a callback with some bound event handlers.
     *
     * @param callable $callable The callback to run.
     * @param callable[] $eventHandlers Event handlers indexed by their event names.
     *
     * @return mixed
     */
    public function runWithBoundEvents(callable $callable, array $eventHandlers) {
        $eventManager = $this->getEventManager();
        foreach ($eventHandlers as $eventName => $eventHandler) {
            $eventManager->bind($eventName, $eventHandler);
        }

        $result = call_user_func($callable);

        foreach ($eventHandlers as $eventName => $eventHandler) {
            $eventManager->unbind($eventName, $eventHandler);
        }
        return $result;
    }
}
