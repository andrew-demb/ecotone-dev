<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_2\Infrastructure;

use App\ReactiveSystem\Part_2\Domain\Order\ShippingAddress;
use App\ReactiveSystem\Part_2\Domain\Shipping\ShippingService;
use Ramsey\Uuid\UuidInterface;

final class StubShippingService implements ShippingService
{
    public function shipOrderFor(UuidInterface $userId, UuidInterface $orderId, array $productDetails, ShippingAddress $shippingAddress): void
    {
        /** In production run we would Shipping Service over HTTP  */

        echo sprintf("\n Shipping products to %s %s! \n", $shippingAddress->street, $shippingAddress->houseNumber);
    }
}