<?php

namespace Oro\Bundle\DistributionBundle;

use Oro\Bundle\DistributionBundle\DependencyInjection\Compiler\CacheConfigurationPass;
use Oro\Bundle\DistributionBundle\DependencyInjection\Compiler\HiddenRoutesPass;
use Oro\Component\DependencyInjection\Compiler\PriorityTaggedServiceViaAddMethodCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The DistributionBundle bundle class.
 */
class OroDistributionBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new CacheConfigurationPass());
        $container->addCompilerPass(new PriorityTaggedServiceViaAddMethodCompilerPass(
            'oro_distribution.routing_options_resolver',
            'routing.options_resolver',
            'addResolver'
        ));
        $container->addCompilerPass(new HiddenRoutesPass());
    }
}
