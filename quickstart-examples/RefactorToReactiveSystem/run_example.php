<?php

use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . "/vendor/autoload.php";
require __DIR__ . '/configuration.php';

Assert::assertTrue(key_exists(1, $argv) && in_array($argv[1], ["Stage_1", "Stage_2", "Stage_3"]), sprintf('Pass correct part which you want to run for example: "php run_example Stage_1"'));
$stageToRun = $argv[1];
$userId = Uuid::uuid4();
$tableProductId = Uuid::uuid4();
$chairProductId = Uuid::uuid4();

$messagingSystem = getConfiguredMessagingSystem($stageToRun, $userId, $chairProductId, $tableProductId);

/** Run Controller  */

/** @var \App\ReactiveSystem\OrderController $controller */
$orderController = $messagingSystem->getServiceFromContainer(sprintf("App\ReactiveSystem\%s\UI\OrderController", $stageToRun));

$orderController->placeOrder(new Request(content: json_encode([
    'address' => [
        'street' => 'Washington',
        'houseNumber' => '15',
        'postCode' => '81-221',
        'country' => 'Netherlands'
    ],
    'productIds' => [$tableProductId->toString(), $chairProductId->toString()]
])));

if ($stageToRun !== 'Stage_1') {
    $messagingSystem->run("asynchronous", ExecutionPollingMetadata::createWithDefaults()->withTestingSetup(2));
}