<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\EcommerceFrameworkBundle\DependencyInjection;

use Pimcore\Bundle\EcommerceFrameworkBundle\DependencyInjection\IndexService\AttributeFactory;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\VarDumper\VarDumper;

class PimcoreEcommerceFrameworkExtension extends ConfigurableExtension
{
    const SERVICE_ID_ENVIRONMENT = 'pimcore_ecommerce.environment';
    const SERVICE_ID_PRICING_MANAGER = 'pimcore_ecommerce.pricing_manager';
    const SERVICE_ID_PAYMENT_MANAGER = 'pimcore_ecommerce.payment_manager';
    const SERVICE_ID_INDEX_SERVICE = 'pimcore_ecommerce.index_service';
    const SERVICE_ID_VOUCHER_SERVICE = 'pimcore_ecommerce.voucher_service';
    const SERVICE_ID_TOKEN_MANAGER_FACTORY = 'pimcore_ecommerce.voucher_service.token_manager_factory';
    const SERVICE_ID_OFFER_TOOL = 'pimcore_ecommerce.offer_tool';
    const SERVICE_ID_TRACKING_MANAGER = 'pimcore_ecommerce.tracking.tracking_manager';

    /**
     * The services below are defined as public as the Factory loads services via get() on
     * demand.
     *
     * @inheritDoc
     */
    protected function loadInternal(array $config, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );

        $loader->load('services.yml');
        $loader->load('environment.yml');
        $loader->load('cart_manager.yml');
        $loader->load('order_manager.yml');
        $loader->load('pricing_manager.yml');
        $loader->load('price_systems.yml');
        $loader->load('availability_systems.yml');
        $loader->load('checkout_manager.yml');
        $loader->load('payment_manager.yml');
        $loader->load('index_service.yml');
        $loader->load('filter_service.yml');
        $loader->load('voucher_service.yml');
        $loader->load('offer_tool.yml');
        $loader->load('tracking_manager.yml');

        $orderManagerTenants = array_keys($config['order_manager']['tenants'] ?? []);

        $this->registerEnvironmentConfiguration($container, $config['environment']);
        $this->registerCartManagerConfiguration($container, $config['cart_manager'], $orderManagerTenants);
        $this->registerOrderManagerConfiguration($container, $config['order_manager']);
        $this->registerPricingManagerConfiguration($container, $config['pricing_manager']);
        $this->registerPriceSystemsConfiguration($container, $config['price_systems']);
        $this->registerAvailabilitySystemsConfiguration($container, $config['availability_systems']);
        $this->registerCheckoutManagerConfiguration($container, $config['checkout_manager'], $orderManagerTenants);
        $this->registerPaymentManagerConfiguration($container, $config['payment_manager']);
        $this->registerIndexServiceConfig($container, $config['index_service']);
        $this->registerFilterServiceConfig($container, $config['filter_service']);
        $this->registerVoucherServiceConfig($container, $config['voucher_service']);
        $this->registerOfferToolConfig($container, $config['offer_tool']);
        $this->registerTrackingManagerConfiguration($container, $config['tracking_manager']);
    }

    private function registerEnvironmentConfiguration(ContainerBuilder $container, array $config)
    {
        $container->setAlias(
            self::SERVICE_ID_ENVIRONMENT,
            $config['environment_id']
        );

        $container->setParameter('pimcore_ecommerce.environment.options', $config['options']);
    }

    private function registerCartManagerConfiguration(ContainerBuilder $container, array $config, array $orderManagerTenants)
    {
        $mapping = [];

        foreach ($config['tenants'] as $tenant => $tenantConfig) {
            $cartManager = new ChildDefinition($tenantConfig['cart_manager_id']);
            $cartManager->setPublic(true);

            $cartFactory = new ChildDefinition($tenantConfig['cart']['factory_id']);

            if (!empty($tenantConfig['cart']['factory_options'])) {
                $cartFactory->setArgument('$options', $tenantConfig['cart']['factory_options']);
            }

            $priceCalculatorFactory = new ChildDefinition($tenantConfig['price_calculator']['factory_id']);
            $priceCalculatorFactory->setArgument(
                '$modificatorConfig',
                $tenantConfig['price_calculator']['modificators']
            );

            if (!empty($tenantConfig['price_calculator']['factory_options'])) {
                $priceCalculatorFactory->setArgument(
                    '$options',
                    $tenantConfig['price_calculator']['factory_options']
                );
            }

            $cartManager->setArgument('$cartFactory', $cartFactory);
            $cartManager->setArgument('$cartPriceCalculatorFactory', $priceCalculatorFactory);

            $orderManagerTenant = $this->resolveOrderManagerTenant(
                'cart manager',
                $tenant,
                $tenantConfig['order_manager_tenant'],
                $orderManagerTenants
            );

            $cartManager->setArgument('$orderManager', new Reference('pimcore_ecommerce.order_manager.' . $orderManagerTenant));

            $aliasName = sprintf('pimcore_ecommerce.cart_manager.%s', $tenant);
            $container->setDefinition($aliasName, $cartManager);

            $mapping[$tenant] = $aliasName;
        }

        $this->setupLocator($container, 'cart_manager', $mapping);
    }

    private function registerOrderManagerConfiguration(ContainerBuilder $container, array $config)
    {
        $mapping = [];

        foreach ($config['tenants'] as $tenant => $tenantConfig) {
            $orderManager = new ChildDefinition($tenantConfig['order_manager_id']);
            $orderManager->setPublic(true);

            $orderAgentFactory = new ChildDefinition($tenantConfig['order_agent']['factory_id']);

            if (!empty($tenantConfig['order_agent']['factory_options'])) {
                $orderAgentFactory->setArgument('$options', $tenantConfig['order_agent']['factory_options']);
            }

            $orderManager->setArgument('$orderAgentFactory', $orderAgentFactory);

            if (!empty($tenantConfig['options'])) {
                $orderManager->setArgument('$options', $tenantConfig['options']);
            }

            $aliasName = sprintf('pimcore_ecommerce.order_manager.%s', $tenant);
            $container->setDefinition($aliasName, $orderManager);

            $mapping[$tenant] = $aliasName;
        }

        $this->setupLocator($container, 'order_manager', $mapping);
    }

    private function registerPricingManagerConfiguration(ContainerBuilder $container, array $config)
    {
        $container->setAlias(
            self::SERVICE_ID_PRICING_MANAGER,
            $config['pricing_manager_id']
        );

        $container->setParameter('pimcore_ecommerce.pricing_manager.enabled', $config['enabled']);
        $container->setParameter('pimcore_ecommerce.pricing_manager.condition_mapping', $config['conditions']);
        $container->setParameter('pimcore_ecommerce.pricing_manager.action_mapping', $config['actions']);
        $container->setParameter('pimcore_ecommerce.pricing_manager.options', $config['pricing_manager_options']);
    }

    private function registerPriceSystemsConfiguration(ContainerBuilder $container, array $config)
    {
        $mapping = [];

        foreach ($config as $name => $cfg) {
            $aliasName = sprintf('pimcore_ecommerce.price_system.%s', $name);

            $container->setAlias($aliasName, $cfg['id']);
            $mapping[$name] = $aliasName;
        }

        $this->setupLocator($container, 'price_system', $mapping);
    }

    private function registerAvailabilitySystemsConfiguration(ContainerBuilder $container, array $config)
    {
        $mapping = [];

        foreach ($config as $name => $cfg) {
            $aliasName = sprintf('pimcore_ecommerce.availability_system.%s', $name);

            $container->setAlias($aliasName, $cfg['id']);
            $mapping[$name] = $aliasName;
        }

        $this->setupLocator($container, 'availability_system', $mapping);
    }

    private function registerCheckoutManagerConfiguration(ContainerBuilder $container, array $config, array $orderManagerTenants)
    {
        $commitOrderProcessorMapping   = [];
        $checkoutManagerFactoryMapping = [];

        foreach ($config['tenants'] as $tenant => $tenantConfig) {
            $orderManagerTenant = $this->resolveOrderManagerTenant(
                'checkout manager factory',
                $tenant,
                $tenantConfig['order_manager_tenant'],
                $orderManagerTenants
            );

            $orderManagerRef = new Reference('pimcore_ecommerce.order_manager.' . $orderManagerTenant);

            $commitOrderProcessor = new ChildDefinition($tenantConfig['commit_order_processor']['id']);
            $commitOrderProcessor->setArgument('$orderManager', $orderManagerRef);

            $checkoutManagerFactory = new ChildDefinition($tenantConfig['factory_id']);
            $checkoutManagerFactory->setArguments([
                '$orderManager'            => $orderManagerRef,
                '$commitOrderProcessor'    => $commitOrderProcessor,
                '$checkoutStepDefinitions' => $tenantConfig['steps'],
            ]);

            if (!empty($tenantConfig['factory_options'])) {
                $checkoutManagerFactory->setArgument('$options', $tenantConfig['factory_options']);
            }

            if (null !== $tenantConfig['payment']['provider']) {
                $checkoutManagerFactory->setArgument('$paymentProvider', new Reference(sprintf(
                    'pimcore_ecommerce.payment_manager.provider.%s',
                    $tenantConfig['payment']['provider']
                )));
            }

            $commitOrderProcessorAliasName = sprintf(
                'pimcore_ecommerce.checkout_manager.%s.commit_order_processor',
                $tenant
            );

            $checkoutManagerFactoryAliasName = sprintf(
                'pimcore_ecommerce.checkout_manager.%s.factory',
                $tenant
            );

            $container->setDefinition($commitOrderProcessorAliasName, $commitOrderProcessor);
            $container->setDefinition($checkoutManagerFactoryAliasName, $checkoutManagerFactory);

            $commitOrderProcessorMapping[$tenant]   = $commitOrderProcessorAliasName;
            $checkoutManagerFactoryMapping[$tenant] = $checkoutManagerFactoryAliasName;
        }

        $this->setupLocator($container, 'checkout_manager.commit_order_processor', $commitOrderProcessorMapping);
        $this->setupLocator($container, 'checkout_manager.factory', $checkoutManagerFactoryMapping);
    }

    private function registerPaymentManagerConfiguration(ContainerBuilder $container, array $config)
    {
        $container->setAlias(self::SERVICE_ID_PAYMENT_MANAGER, $config['payment_manager_id']);

        $mapping = [];

        foreach ($config['providers'] as $name => $providerConfig) {
            if (!isset($providerConfig['profiles'][$providerConfig['profile']])) {
                throw new InvalidConfigurationException(sprintf(
                    'Payment provider "%s" is configured to use profile "%s", but profile is not defined',
                    $name,
                    $providerConfig['profile']
                ));
            }

            $profileConfig = $providerConfig['profiles'][$providerConfig['profile']];

            $provider = new ChildDefinition($providerConfig['provider_id']);
            if (!empty($profileConfig)) {
                $provider->setArgument('$options', $profileConfig);
            }

            $serviceId = sprintf('pimcore_ecommerce.payment_manager.provider.%s', $name);
            $container->setDefinition($serviceId, $provider);

            $mapping[$name] = $serviceId;
        }

        $this->setupLocator($container, 'payment_manager.provider', $mapping);
    }

    private function registerIndexServiceConfig(ContainerBuilder $container, array $config)
    {
        $container->setAlias(
            self::SERVICE_ID_INDEX_SERVICE,
            $config['index_service_id']
        );

        $container->setParameter('pimcore_ecommerce.index_service.default_tenant', $config['default_tenant']);

        $attributeFactory = new AttributeFactory();

        foreach ($config['tenants'] ?? [] as $tenant => $tenantConfig) {
            if (!$tenantConfig['enabled']) {
                continue;
            }

            $configId = sprintf('pimcore_ecommerce.index_service.%s.config', $tenant);
            $workerId = sprintf('pimcore_ecommerce.index_service.%s.worker', $tenant);

            $config = new ChildDefinition($tenantConfig['config_id']);
            $config->setArguments([
                '$tenantName'       => $tenant,
                '$attributes'       => $attributeFactory->createAttributes($tenantConfig['attributes']),
                '$searchAttributes' => $tenantConfig['search_attributes'],
                '$filterTypes'      => []
            ]);

            if (!empty($tenantConfig['config_options'])) {
                $config->setArgument('$options', $tenantConfig['config_options']);
            }

            $worker = new ChildDefinition($tenantConfig['worker_id']);
            $worker->setArgument('$tenantConfig', new Reference($configId));
            $worker->addTag('pimcore_ecommerce.index_service.worker', ['tenant' => $tenant]);

            $container->setDefinition($configId, $config);
            $container->setDefinition($workerId, $worker);
        }
    }

    private function registerFilterServiceConfig(ContainerBuilder $container, array $config)
    {
        $mapping = [];

        foreach ($config['tenants'] ?? [] as $tenant => $tenantConfig) {
            if (!$tenantConfig['enabled']) {
                continue;
            }

            $filterTypes = [];
            foreach ($tenantConfig['filter_types'] ?? [] as $filterTypeName => $filterTypeConfig) {
                $filterType = new ChildDefinition($filterTypeConfig['filter_type_id']);
                $filterType->setArgument('$template', $filterTypeConfig['template']);

                if (!empty($filterTypeConfig['options'])) {
                    $filterType->setArgument('$options', $filterTypeConfig['options']);
                }

                $filterTypes[$filterTypeName] = $filterType;
            }

            $filterService = new ChildDefinition($tenantConfig['service_id']);
            $filterService->setArgument('$filterTypes', $filterTypes);

            $serviceId = sprintf('pimcore_ecommerce.filter_service.%s', $tenant);
            $container->setDefinition($serviceId, $filterService);

            $mapping[$tenant] = $serviceId;
        }

        $this->setupLocator($container, 'filter_service', $mapping);
    }

    private function registerVoucherServiceConfig(ContainerBuilder $container, array $config)
    {
        // voucher service options are referenced in service definition
        $container->setParameter(
            'pimcore_ecommerce.voucher_service.options',
            $config['voucher_service_options']
        );

        $container->setAlias(
            self::SERVICE_ID_VOUCHER_SERVICE,
            $config['voucher_service_id']
        );

        $container->setParameter(
            'pimcore_ecommerce.voucher_service.token_manager.mapping',
            $config['token_managers']['mapping']
        );

        $container->setAlias(
            self::SERVICE_ID_TOKEN_MANAGER_FACTORY,
            $config['token_managers']['factory_id']
        );
    }

    private function registerOfferToolConfig(ContainerBuilder $container, array $config)
    {
        $container->setAlias(
            self::SERVICE_ID_OFFER_TOOL,
            $config['service_id']
        );

        $container->setParameter(
            'pimcore_ecommerce.offer_tool.order_storage.offer_class',
            $config['order_storage']['offer_class']
        );

        $container->setParameter(
            'pimcore_ecommerce.offer_tool.order_storage.offer_item_class',
            $config['order_storage']['offer_item_class']
        );

        $container->setParameter(
            'pimcore_ecommerce.offer_tool.order_storage.parent_folder_path',
            $config['order_storage']['parent_folder_path']
        );
    }

    private function registerTrackingManagerConfiguration(ContainerBuilder $container, array $config)
    {
        $container->setAlias(
            self::SERVICE_ID_TRACKING_MANAGER,
            $config['tracking_manager_id']
        );

        foreach ($config['trackers'] as $name => $trackerConfig) {
            if (!$trackerConfig['enabled']) {
                continue;
            }

            $tracker = new ChildDefinition($trackerConfig['id']);

            if (null !== $trackerConfig['item_builder_id']) {
                $tracker->setArgument('$trackingItemBuilder', new Reference($trackerConfig['item_builder_id']));
            }

            if (!empty($trackerConfig['options'])) {
                $tracker->setArgument('$options', $trackerConfig['options']);
            }

            $tracker->addTag('pimcore_ecommerce.tracking.tracker', ['name' => $name]);

            $container->setDefinition(sprintf('pimcore_ecommerce.tracking.tracker.%s', $name), $tracker);
        }
    }

    /**
     * If an order manager is explicitely configured (order_manager_tenant) just use the configured one. If not, try
     * to find an order manager with the same tenant first and fall back to "default" if none is found.
     *
     * @param string $subSystem
     * @param string $tenant
     * @param string|null $configuredTenant
     * @param array $orderManagerTenants
     *
     * @return string
     */
    private function resolveOrderManagerTenant(string $subSystem, string $tenant, string $configuredTenant = null, array $orderManagerTenants)
    {
        if (null !== $configuredTenant) {
            if (!in_array($configuredTenant, $orderManagerTenants)) {
                throw new InvalidConfigurationException(sprintf(
                    'Failed to find order manager for %s with tenant "%s" as the configured order manager "%s" is not defined. Please check the configuration.',
                    $subSystem,
                    $tenant,
                    $configuredTenant
                ));
            }

            return $configuredTenant;
        }

        $tenantVariants = [$tenant, 'default'];
        foreach ($tenantVariants as $tenantVariant) {
            if (in_array($tenantVariant, $orderManagerTenants)) {
                return $tenantVariant;
            }
        }

        throw new InvalidConfigurationException(sprintf(
            'Failed to find order manager for %s with tenant "%s". Tried: %s. Please check the configuration.',
            $subSystem,
            $tenant,
            implode(', ', $tenantVariants)
        ));
    }

    private function setupLocator(ContainerBuilder $container, string $id, array $mapping)
    {
        foreach ($mapping as $name => $reference) {
            $mapping[$name] = new Reference($reference);
        }

        $locator = new Definition(ServiceLocator::class, [$mapping]);
        $locator->setPublic(false);
        $locator->addTag('container.service_locator');

        $container->setDefinition(sprintf('pimcore_ecommerce.locator.%s', $id), $locator);
    }
}
