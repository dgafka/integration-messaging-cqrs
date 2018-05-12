<?php

namespace SimplyCodedSoftware\IntegrationMessaging\Cqrs\Config;

use SimplyCodedSoftware\IntegrationMessaging\Annotation\MessageEndpointAnnotation;
use SimplyCodedSoftware\IntegrationMessaging\Annotation\MessageToParameter\MessageToPayloadParameterAnnotation;
use SimplyCodedSoftware\IntegrationMessaging\Annotation\ModuleAnnotation;
use SimplyCodedSoftware\IntegrationMessaging\Channel\SimpleMessageChannelBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Config\Annotation\AnnotationModule;
use SimplyCodedSoftware\IntegrationMessaging\Config\Annotation\AnnotationRegistration;
use SimplyCodedSoftware\IntegrationMessaging\Config\Annotation\AnnotationRegistrationService;
use SimplyCodedSoftware\IntegrationMessaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use SimplyCodedSoftware\IntegrationMessaging\Config\Configuration;
use SimplyCodedSoftware\IntegrationMessaging\Config\ConfigurationException;
use SimplyCodedSoftware\IntegrationMessaging\Config\ConfigurationObserver;
use SimplyCodedSoftware\IntegrationMessaging\Config\ConfigurationVariableRetrievingService;
use SimplyCodedSoftware\IntegrationMessaging\Config\ConfiguredMessagingSystem;
use SimplyCodedSoftware\IntegrationMessaging\Cqrs\AggregateRepository;
use SimplyCodedSoftware\IntegrationMessaging\Cqrs\AggregateRepositoryFactory;
use SimplyCodedSoftware\IntegrationMessaging\Cqrs\Annotation\AggregateAnnotation;
use SimplyCodedSoftware\IntegrationMessaging\Cqrs\Annotation\CommandHandlerAnnotation;
use SimplyCodedSoftware\IntegrationMessaging\Cqrs\Annotation\QueryHandlerAnnotation;
use SimplyCodedSoftware\IntegrationMessaging\Cqrs\CallInterceptor;
use SimplyCodedSoftware\IntegrationMessaging\Cqrs\CqrsMessageHandlerBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Handler\InterfaceToCall;
use SimplyCodedSoftware\IntegrationMessaging\Handler\MessageHandlerBuilderWithParameterConverters;
use SimplyCodedSoftware\IntegrationMessaging\Handler\ReferenceSearchService;
use SimplyCodedSoftware\IntegrationMessaging\Handler\Router\RouterBuilder;
use SimplyCodedSoftware\IntegrationMessaging\Support\InvalidArgumentException;

/**
 * Class IntegrationMessagingCqrsModule
 * @package SimplyCodedSoftware\IntegrationMessaging\Cqrs\Config
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 * @ModuleAnnotation()
 */
class CqrsMessagingModule implements AnnotationModule, AggregateRepositoryFactory
{
    const INTEGRATION_MESSAGING_CQRS_MESSAGE_EXECUTING_CHANNEL          = "cqrs.execute_message";
    const INTEGRATION_MESSAGING_CQRS_AGGREGATE_HEADER                   = "cqrs.aggregate";
    const INTEGRATION_MESSAGING_CQRS_AGGREGATE_CLASS_NAME_HEADER        = "cqrs.aggregate.class_name";
    const INTEGRATION_MESSAGING_CQRS_AGGREGATE_METHOD_HEADER            = "cqrs.aggregate.method";
    const INTEGRATION_MESSAGING_CQRS_AGGREGATE_REPOSITORY_HEADER        = "cqrs.aggregate.repository";
    const INTEGRATION_MESSAGING_CQRS_AGGREGATE_IS_FACTORY_METHOD_HEADER = "cqrs.aggregate.is_factory_method";
    const INTEGRATION_MESSAGING_CQRS_AGGREGATE_ID_HEADER                = "cqrs.aggregate.id";
    const INTEGRATION_MESSAGING_CQRS_AGGREGATE_EXPECTED_VERSION_HEADER  = "cqrs.aggregate.expected_version";
    const INTEGRATION_MESSAGING_CQRS_AGGREGATE_MESSAGE_HEADER           = "cqrs.aggregate.calling_message";
    const CQRS_MODULE                                                   = "cqrsModule";

    /**
     * @var ParameterConverterAnnotationFactory
     */
    private $parameterConverterAnnotationFactory;
    /**
     * @var AggregateRepositoryConstructor[]
     */
    private $aggregateRepositoryConstructors;

    /**
     * @var AnnotationRegistration[]
     */
    private $serviceCommandHandlerRegistrations;
    /**
     * @var AnnotationRegistration[]
     */
    private $serviceQueryHandlerRegistrations;
    /**
     * @var AnnotationRegistration[]
     */
    private $aggregateCommandHandlerRegistrations;
    /**
     * @var AnnotationRegistration[]
     */
    private $aggregateQueryHandlerRegistrations;

    /**
     * CqrsMessagingModule constructor.
     *
     * @param ParameterConverterAnnotationFactory $parameterConverterAnnotationFactory
     * @param AnnotationRegistration[]            $serviceCommandHandlerRegistrations
     * @param AnnotationRegistration[]            $serviceQueryHandlerRegistrations
     * @param AnnotationRegistration[]            $aggregateCommandHandlerRegistrations
     * @param AnnotationRegistration[]            $aggregateQueryHandlerRegistrations
     */
    private function __construct(
        ParameterConverterAnnotationFactory $parameterConverterAnnotationFactory,
        array $serviceCommandHandlerRegistrations,
        array $serviceQueryHandlerRegistrations,
        array $aggregateCommandHandlerRegistrations,
        array $aggregateQueryHandlerRegistrations
    )
    {
        $this->parameterConverterAnnotationFactory  = $parameterConverterAnnotationFactory;
        $this->serviceCommandHandlerRegistrations   = $serviceCommandHandlerRegistrations;
        $this->serviceQueryHandlerRegistrations     = $serviceQueryHandlerRegistrations;
        $this->aggregateCommandHandlerRegistrations = $aggregateCommandHandlerRegistrations;
        $this->aggregateQueryHandlerRegistrations   = $aggregateQueryHandlerRegistrations;
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationRegistrationService $annotationRegistrationService): AnnotationModule
    {
        return new self(
            ParameterConverterAnnotationFactory::create(),
            $annotationRegistrationService->findRegistrationsFor(MessageEndpointAnnotation::class, CommandHandlerAnnotation::class),
            $annotationRegistrationService->findRegistrationsFor(MessageEndpointAnnotation::class, QueryHandlerAnnotation::class),
            $annotationRegistrationService->findRegistrationsFor(AggregateAnnotation::class, CommandHandlerAnnotation::class),
            $annotationRegistrationService->findRegistrationsFor(AggregateAnnotation::class, QueryHandlerAnnotation::class)
        );
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::CQRS_MODULE;
    }

    /**
     * @inheritDoc
     */
    public function getConfigurationVariables(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $configuration, array $moduleExtensions, ConfigurationObserver $configurationObserver): void
    {
        foreach ($this->serviceCommandHandlerRegistrations as $registration) {
            list($interfaceToCall, $inputMessageChannelName) = $this->getInputChannelFor($registration, true);

            $handler = CqrsMessageHandlerBuilder::createServiceCommandHandlerWith($inputMessageChannelName, $registration->getReferenceName(), $registration->getMethodName());
            $this->registerChannelAndHandler($configuration, $interfaceToCall, $handler, $registration, $inputMessageChannelName);
        }

        foreach ($this->serviceQueryHandlerRegistrations as $registration) {
            list($interfaceToCall, $inputMessageChannelName) = $this->getInputChannelFor($registration, false);

            $handler = CqrsMessageHandlerBuilder::createServiceQueryHandlerWith($inputMessageChannelName, $registration->getReferenceName(), $registration->getMethodName());
            $this->registerChannelAndQueryHandler($configuration, $handler, $registration, $interfaceToCall, $inputMessageChannelName);

        }

        foreach ($this->aggregateCommandHandlerRegistrations as $registration) {
            list($interfaceToCall, $inputMessageChannelName) = $this->getInputChannelFor($registration, true);

            $handler = CqrsMessageHandlerBuilder::createAggregateCommandHandlerWith($inputMessageChannelName, $registration->getClassWithAnnotation(), $registration->getMethodName());
            $this->registerChannelAndHandler($configuration, $interfaceToCall, $handler, $registration, $inputMessageChannelName);
        }

        foreach ($this->aggregateQueryHandlerRegistrations as $registration) {
            list($interfaceToCall, $inputMessageChannelName) = $this->getInputChannelFor($registration, false);

            $handler = CqrsMessageHandlerBuilder::createAggregateQueryHandlerWith($inputMessageChannelName, $registration->getClassWithAnnotation(), $registration->getMethodName());
            $this->registerChannelAndQueryHandler($configuration, $handler, $registration, $interfaceToCall, $inputMessageChannelName);
        }

        $configuration
            ->registerMessageChannel(SimpleMessageChannelBuilder::createDirectMessageChannel(self::INTEGRATION_MESSAGING_CQRS_MESSAGE_EXECUTING_CHANNEL))
            ->registerMessageHandler(RouterBuilder::createPayloadTypeRouterByClassName(self::INTEGRATION_MESSAGING_CQRS_MESSAGE_EXECUTING_CHANNEL));

        $this->aggregateRepositoryConstructors = $moduleExtensions;
    }

    /**
     * @param InterfaceToCall        $interfaceToCall
     * @param AnnotationRegistration $annotation
     *
     * @return string
     * @throws ConfigurationException
     * @throws \SimplyCodedSoftware\IntegrationMessaging\MessagingException
     */
    private function getInputMessageChannel(InterfaceToCall $interfaceToCall, AnnotationRegistration $annotation): string
    {
        $messageClassName = $annotation->getAnnotationForMethod()->messageClassName;
        if ($messageClassName) {
            $inputChannel = $messageClassName;

            if (!$inputChannel) {
                throw ConfigurationException::create("{$interfaceToCall} has no information about command. Did you forget to typehint or add annotation?");
            }

            return $inputChannel;
        }

        return $interfaceToCall->getFirstParameterTypeHint();
    }

    /**
     * @param Configuration $configuration
     * @param               $interfaceToCall
     * @param               $handler
     * @param               $registration
     * @param               $inputMessageChannelName
     */
    private function registerChannelAndHandler(Configuration $configuration, InterfaceToCall $interfaceToCall, CqrsMessageHandlerBuilder $handler, AnnotationRegistration $registration, string $inputMessageChannelName): void
    {
        $this->configureMessageParametersFor($interfaceToCall, $handler, $registration);
        $this->registerPreCallInterceptors($registration, $handler);
        $this->registerPostCallInterceptors($registration, $handler);

        $configuration->registerMessageChannel(SimpleMessageChannelBuilder::createDirectMessageChannel($inputMessageChannelName));
        $configuration->registerMessageHandler($handler);
    }

    /**
     * @param InterfaceToCall                              $interfaceToCall
     * @param MessageHandlerBuilderWithParameterConverters $messageHandlerBuilderWithParameterConverters
     * @param AnnotationRegistration                       $annotationRegistration
     *
     * @return void
     */
    private function configureMessageParametersFor(InterfaceToCall $interfaceToCall, MessageHandlerBuilderWithParameterConverters $messageHandlerBuilderWithParameterConverters, AnnotationRegistration $annotationRegistration): void
    {
        if ($interfaceToCall->hasNoParameters()) {
            return;
        }

        $methodAnnotation              = $annotationRegistration->getAnnotationForMethod();
        $parameterConverterAnnotations = isset($methodAnnotation->parameterConverters) ? $methodAnnotation->parameterConverters : [];

        if (!$annotationRegistration->getAnnotationForMethod()->messageClassName) {
            $messageParameter                = new MessageToPayloadParameterAnnotation();
            $messageParameter->parameterName = $interfaceToCall->getFirstParameterName();
            array_unshift($parameterConverterAnnotations, $messageParameter);
        }

        $this->parameterConverterAnnotationFactory->configureParameterConverters(
            $messageHandlerBuilderWithParameterConverters,
            $annotationRegistration->getClassWithAnnotation(),
            $annotationRegistration->getMethodName(),
            $parameterConverterAnnotations
        );
    }

    /**
     * @param $registration
     * @param $handler
     */
    private function registerPreCallInterceptors(AnnotationRegistration $registration, CqrsMessageHandlerBuilder $handler): void
    {
        $annotationForMethod = $registration->getAnnotationForMethod();
        $preCallInterceptors = [];
        foreach ($annotationForMethod->preCallInterceptors as $preCallInterceptor) {
            $parameterConverters = $this->parameterConverterAnnotationFactory->createParameterConverters($handler, $registration->getClassWithAnnotation(), $registration->getMethodName(), $preCallInterceptor->parameterConverters);

            $preCallInterceptors[] = CallInterceptor::create($preCallInterceptor->referenceName, $preCallInterceptor->methodName, $parameterConverters);
        }
        $handler->withPreCallInterceptors($preCallInterceptors);
    }

    /**
     * @param $registration
     * @param $handler
     */
    private function registerPostCallInterceptors(AnnotationRegistration $registration, CqrsMessageHandlerBuilder $handler): void
    {
        $annotationForMethod = $registration->getAnnotationForMethod();
        $postCallInterceptors = [];
        foreach ($annotationForMethod->postCallInterceptors as $preCallInterceptor) {
            $parameterConverters = $this->parameterConverterAnnotationFactory->createParameterConverters($handler, $registration->getClassWithAnnotation(), $registration->getMethodName(), $preCallInterceptor->parameterConverters);

            $postCallInterceptors[] = CallInterceptor::create($preCallInterceptor->referenceName, $preCallInterceptor->methodName, $parameterConverters);
        }
        $handler->withPostCallInterceptors($postCallInterceptors);
    }

    /**
     * @param Configuration             $configuration
     * @param CqrsMessageHandlerBuilder $handler
     * @param AnnotationRegistration    $registration
     * @param InterfaceToCall           $interfaceToCall
     * @param string                    $inputMessageChannelName
     */
    private function registerChannelAndQueryHandler(Configuration $configuration, CqrsMessageHandlerBuilder $handler, AnnotationRegistration $registration, InterfaceToCall $interfaceToCall, string $inputMessageChannelName): void
    {
        $handler->withOutputMessageChannel($registration->getAnnotationForMethod()->outputChannelName);

        $this->registerChannelAndHandler($configuration, $interfaceToCall, $handler, $registration, $inputMessageChannelName);
    }

    /**
     * @inheritDoc
     */
    public function configure(Configuration $configuration, array $moduleExtensions, ConfigurationVariableRetrievingService $configurationVariableRetrievingService, ReferenceSearchService $referenceSearchService): void
    {
        return;
    }

    /**
     * @inheritDoc
     */
    public function postConfigure(ConfiguredMessagingSystem $configuredMessagingSystem): void
    {
        return;
    }

    /**
     * @inheritDoc
     */
    public function getRepositoryFor(ReferenceSearchService $referenceSearchService, string $aggregateClassName): AggregateRepository
    {
        foreach ($this->aggregateRepositoryConstructors as $aggregateRepositoryConstructor) {
            if ($aggregateRepositoryConstructor->canHandle($referenceSearchService, $aggregateClassName)) {
                return $aggregateRepositoryConstructor->build($referenceSearchService, $aggregateClassName);
            }
        }

        throw new InvalidArgumentException("No suitable aggregate repository for {$aggregateClassName}. Have you registered your aggregate?");
    }

    /**
     * @param AnnotationRegistration $registration
     * @param bool                   $isCommandHandler
     *
     * @return array
     * @throws InvalidArgumentException
     * @throws \SimplyCodedSoftware\IntegrationMessaging\MessagingException
     */
    private function getInputChannelFor(AnnotationRegistration $registration, bool $isCommandHandler): array
    {
        $interfaceToCall         = InterfaceToCall::create($registration->getClassWithAnnotation(), $registration->getMethodName());
        $inputMessageChannelName = $this->getInputMessageChannel($interfaceToCall, $registration);

        if ($isCommandHandler && !$interfaceToCall->isStaticallyCalled() && $interfaceToCall->hasReturnValue()) {
            throw InvalidArgumentException::create("Command handler {$interfaceToCall} must not return value. Change return type to `void`");
        }

        if (!$isCommandHandler && !$interfaceToCall->hasReturnValue()) {
            throw InvalidArgumentException::create("Query handler {$interfaceToCall} must return value. Change return value from `void` to result type");
        }

        return array($interfaceToCall, $inputMessageChannelName);
    }
}