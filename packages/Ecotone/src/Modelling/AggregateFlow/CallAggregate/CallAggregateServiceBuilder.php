<?php

namespace Ecotone\Modelling\AggregateFlow\CallAggregate;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodArgumentsFactory;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\Attribute\AggregateEvents;
use Ecotone\Modelling\Attribute\AggregateVersion;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingSaga;
use Ecotone\Modelling\EventSourcingHandlerExecutor;
use Ecotone\Modelling\WithAggregateVersioning;

/**
 * licence Apache-2.0
 */
class CallAggregateServiceBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilderWithParameterConverters
{
    private InterfaceToCall $interfaceToCall;
    /**
     * @var ParameterConverterBuilder[]
     */
    private array $methodParameterConverterBuilders = [];
    /**
     * @var bool
     */
    private bool $isCommandHandler;
    private EventSourcingHandlerExecutor $eventSourcingHandlerExecutor;
    private ?string $aggregateMethodWithEvents;
    private ?string $aggregateVersionProperty;
    private bool $isEventSourced = false;
    private bool $isReturningAggregate = false;

    private function __construct(ClassDefinition $aggregateClassDefinition, string $methodName, bool $isCommandHandler, InterfaceToCallRegistry $interfaceToCallRegistry)
    {
        $this->isCommandHandler = $isCommandHandler;

        $this->initialize($aggregateClassDefinition, $methodName, $interfaceToCallRegistry);
    }

    private function initialize(ClassDefinition $aggregateClassDefinition, string $methodName, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $interfaceToCall = $interfaceToCallRegistry->getFor($aggregateClassDefinition->getClassType()->toString(), $methodName);

        $this->isReturningAggregate = $interfaceToCall->isReturningAggregate($interfaceToCallRegistry);

        $aggregateMethodWithEvents    = null;
        $aggregateEventsAnnotation = TypeDescriptor::create(AggregateEvents::class);
        foreach ($aggregateClassDefinition->getPublicMethodNames() as $method) {
            $methodToCheck = $interfaceToCallRegistry->getFor($aggregateClassDefinition->getClassType()->toString(), $method);

            if ($methodToCheck->hasMethodAnnotation($aggregateEventsAnnotation)) {
                $aggregateMethodWithEvents = $method;
                break;
            }
        }
        $this->aggregateMethodWithEvents = $aggregateMethodWithEvents;

        $eventSourcedAggregateAnnotation = TypeDescriptor::create(EventSourcingAggregate::class);
        $eventSourcedSagaAnnotation = TypeDescriptor::create(EventSourcingSaga::class);
        if ($interfaceToCall->hasClassAnnotation($eventSourcedAggregateAnnotation) || $interfaceToCall->hasClassAnnotation($eventSourcedSagaAnnotation)) {
            $this->isEventSourced = true;
        }

        $aggregateVersionPropertyName = null;
        $versionAnnotation             = TypeDescriptor::create(AggregateVersion::class);
        foreach ($aggregateClassDefinition->getProperties() as $property) {
            if ($property->hasAnnotation($versionAnnotation)) {
                $aggregateVersionPropertyName = $property->getName();
                // TODO: should throw exception if more than one version property
            }
        }
        $this->aggregateVersionProperty             = $aggregateVersionPropertyName;

        if ($this->isEventSourced) {
            Assert::isTrue((bool)$this->aggregateVersionProperty, "{$interfaceToCall->getInterfaceName()} is event sourced aggregate. Event Sourced aggregates are required to define version property. Make use of " . WithAggregateVersioning::class . ' or implement your own.');
        }

        $this->interfaceToCall = $interfaceToCall;
        $this->eventSourcingHandlerExecutor = EventSourcingHandlerExecutor::createFor($aggregateClassDefinition, $this->isEventSourced, $interfaceToCallRegistry);
        $isFactoryMethod = $this->interfaceToCall->isFactoryMethod();
        if (! $this->isEventSourced && $isFactoryMethod) {
            Assert::isTrue($this->interfaceToCall->getReturnType()->isClassNotInterface(), "Factory method {$this->interfaceToCall} for standard aggregate should return object. Did you wanted to register Event Sourced Aggregate?");
        }
    }

    public static function create(ClassDefinition $aggregateClassDefinition, string $methodName, bool $isCommandHandler, InterfaceToCallRegistry $interfaceToCallRegistry): self
    {
        return new self($aggregateClassDefinition, $methodName, $isCommandHandler, $interfaceToCallRegistry);
    }

    /**
     * @inheritDoc
     */
    public function getParameterConverters(): array
    {
        return $this->methodParameterConverterBuilders;
    }

    /**
     * @inheritDoc
     */
    public function withMethodParameterConverters(array $methodParameterConverterBuilders): self
    {
        Assert::allInstanceOfType($methodParameterConverterBuilders, ParameterConverterBuilder::class);

        $this->methodParameterConverterBuilders = $methodParameterConverterBuilders;

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $interceptors = [];
        foreach (AroundInterceptorBuilder::orderedInterceptors($this->orderedAroundInterceptors) as $aroundInterceptorReference) {
            $interceptors[] = $aroundInterceptorReference->compile($builder, $this->getEndpointAnnotations(), $this->interfaceToCall);
        }

        // TODO: code duplication with ServiceActivatorBuilder
        $methodParameterConverterBuilders = MethodArgumentsFactory::createDefaultMethodParameters($this->interfaceToCall, $this->methodParameterConverterBuilders, $this->getEndpointAnnotations(), null, false);

        $compiledMethodParameterConverters = [];
        foreach ($methodParameterConverterBuilders as $index => $methodParameterConverter) {
            $compiledMethodParameterConverters[] = $methodParameterConverter->compile($builder, $this->interfaceToCall, $this->interfaceToCall->getInterfaceParameters()[$index]);
        }

        if ($this->isEventSourced) {
            $callAggregateService = $this->callEventSourcedAggregateServiceDefinition($compiledMethodParameterConverters, $interceptors);
        } else {
            $callAggregateService = $this->callStateBasedAggregateServiceDefinition($compiledMethodParameterConverters, $interceptors);
        }

        return ServiceActivatorBuilder::createWithDefinition($callAggregateService, 'call')
            ->withOutputMessageChannel($this->outputMessageChannelName)
            ->compile($builder);
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor($this->interfaceToCall->getInterfaceName(), $this->interfaceToCall->getMethodName());
    }

    public function __toString()
    {
        return sprintf('Aggregate Handler - %s with name `%s` for input channel `%s`', (string)$this->interfaceToCall, $this->getEndpointId(), $this->getInputMessageChannelName());
    }

    private function callEventSourcedAggregateServiceDefinition(array $compiledMethodParameterConverters, array $interceptors): Definition
    {
        return new Definition(CallEventSourcingAggregateService::class, [
            InterfaceToCallReference::fromInstance($this->interfaceToCall),
            $compiledMethodParameterConverters,
            $interceptors,
            new Reference(PropertyReaderAccessor::class),
            $this->isCommandHandler,
            $this->aggregateVersionProperty,
        ]);
    }

    private function callStateBasedAggregateServiceDefinition(array $compiledMethodParameterConverters, array $interceptors): Definition
    {
        return new Definition(CallStateBasedAggregateService::class, [
            InterfaceToCallReference::fromInstance($this->interfaceToCall),
            $compiledMethodParameterConverters,
            $interceptors,
            $this->isCommandHandler,
        ]);
    }
}
