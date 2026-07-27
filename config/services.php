<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusMarkdown\EventListener\MarkdownNegotiationListener;
use Koh\SyliusMarkdown\EventListener\VaryAcceptContributor;
use Koh\SyliusMarkdown\Negotiation\AcceptParser;
use Koh\SyliusMarkdown\Provider\ProductMarkdownProvider;
use Koh\SyliusMarkdown\Renderer\MarkdownNormalizer;
use Koh\SyliusMarkdown\Renderer\MarkdownResolver;
use Koh\SyliusMarkdown\Renderer\TokenEstimator;
use Koh\SyliusMarkdown\Twig\MarkdownExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(AcceptParser::class)->public();
    $services->set(TokenEstimator::class);
    $services->set(MarkdownNormalizer::class)->public();

    $services->set(MarkdownExtension::class)
        ->tag('twig.extension');

    $services->set(MarkdownResolver::class)
        ->args([tagged_iterator('koh_agent.markdown')])
        ->public();

    $services->set(ProductMarkdownProvider::class)
        ->args([
            service('sylius.repository.product'),
            service('twig'),
            service(MarkdownNormalizer::class),
        ])
        ->tag('koh_agent.markdown');

    // Priority 4 sits below the firewall (8): authentication has already run,
    // so a Markdown request is subject to the same access control as the HTML
    // one. Above it, this listener would be an authentication bypass.
    $services->set(MarkdownNegotiationListener::class)
        ->args([
            service(AcceptParser::class),
            service(MarkdownResolver::class),
            service(AgentContextFactoryInterface::class),
            service(TokenEstimator::class),
            param('koh_markdown.max_age'),
            param('koh_markdown.enabled'),
        ])
        ->tag('kernel.event_listener', [
            'event' => 'kernel.request',
            'method' => '__invoke',
            'priority' => 4,
        ]);

    $services->set(VaryAcceptContributor::class)
        ->args([service(MarkdownResolver::class)])
        ->tag('koh_agent.response_header');
};
