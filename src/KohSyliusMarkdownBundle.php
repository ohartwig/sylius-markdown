<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Markdown content negotiation for the Sylius storefront.
 */
final class KohSyliusMarkdownBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_markdown';

    /** templates/ and config/ sit at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->integerNode('max_age')
                    ->defaultValue(3600)
                    ->info('Cache lifetime of a Markdown response.')
                ->end()
                ->booleanNode('products')
                    ->defaultTrue()
                    ->info('Serve Markdown for product pages.')
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('koh_markdown.enabled', (bool) ($config['enabled'] ?? true));
        $builder->setParameter('koh_markdown.max_age', (int) ($config['max_age'] ?? 3600));

        $container->import('../config/services.php');

        if (false === ($config['products'] ?? true)) {
            $builder->removeDefinition(Provider\ProductMarkdownProvider::class);
        }
    }
}
