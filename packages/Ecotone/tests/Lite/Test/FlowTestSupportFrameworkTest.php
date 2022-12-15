<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Test;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\ChangeAssignedPerson;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;
use Test\Ecotone\Modelling\Fixture\Order\OrderService;
use Test\Ecotone\Modelling\Fixture\Order\PlaceOrder;
use Test\Ecotone\Modelling\Fixture\OrderAggregate\Order;

/**
 * @internal
 */
final class FlowTestSupportFrameworkTest extends TestCase
{
    public function test_collecting_commands_routing()
    {
        $flowSupport = EcotoneLite::bootstrapFlowTesting(
            [OrderService::class],
            [new OrderService()]
        );

        $this->assertEquals(
            [['order.register'], ['order.register', '3']],
            $flowSupport
                ->sendCommandWithRoutingKey('order.register', new PlaceOrder('1'))
                ->sendCommandWithRoutingKey('order.register', new PlaceOrder('3'), metadata: ['aggregate.id' => '3'])
                ->sendCommand(new PlaceOrder('2'))
                ->getRecordedCommandsWithRouting()
        );
    }

    public function test_providing_initial_state_in_form_of_state_stored_aggregate()
    {
        $ecotoneTestSupport = EcotoneLite::bootstrapFlowTesting([Order::class]);

        $orderId = '1';

        /** Setting up initial state for state stored aggregate */
        $this->assertTrue(
            $ecotoneTestSupport
                ->withStateFor(Order::register(new PlaceOrder($orderId)))
                ->sendCommandWithRoutingKey('order.cancel', metadata: ["aggregate.id" => $orderId])
                ->getAggregate(Order::class, $orderId)
                ->isCancelled()
        );
    }

    public function test_state_stored_aggregate()
    {
        $flowSupport = EcotoneLite::bootstrapFlowTesting([Order::class]);

        $this->assertEquals(
            1,
            $flowSupport
                ->sendCommandWithRoutingKey('order.register', new PlaceOrder('1'))
                ->getAggregate(Order::class, '1')
                ->getIsNotifiedCount()
        );
    }
}