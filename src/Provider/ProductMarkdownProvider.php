<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Provider\MarkdownRepresentationProviderInterface;
use Koh\SyliusMarkdown\Renderer\MarkdownNormalizer;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * Renders a product page as Markdown from the entity, not from its HTML.
 *
 * Converting the rendered page is the tempting shortcut and produces a document
 * full of navigation, footer, cookie banner and related-product carousels — the
 * furniture an agent has to discard, if it can tell it apart from the content at
 * all. A template over the entity yields the product and nothing else.
 */
final class ProductMarkdownProvider implements MarkdownRepresentationProviderInterface
{
    public const ROUTE = 'sylius_shop_product_show';

    /** @param ProductRepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Environment $twig,
        private readonly MarkdownNormalizer $normalizer,
        private readonly string $template = '@KohSyliusMarkdown/product.md.twig',
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::ROUTE === $request->attributes->get('_route')
            && \is_string($this->slug($request));
    }

    public function render(Request $request, AgentContext $context): ?string
    {
        $slug = $this->slug($request);
        if (!\is_string($slug)) {
            return null;
        }

        $product = $this->productRepository->findOneByChannelAndSlug(
            $context->channel,
            $context->localeCode,
            $slug,
        );

        if (!$product instanceof ProductInterface) {
            return null;
        }

        return $this->normalizer->normalize($this->twig->render($this->template, [
            'product' => $product,
            'channel' => $context->channel,
            'locale' => $context->localeCode,
            'url' => $context->url('/products/'.$slug),
        ]));
    }

    private function slug(Request $request): ?string
    {
        $params = $request->attributes->get('_route_params');
        $slug = \is_array($params) ? ($params['slug'] ?? null) : null;

        return \is_string($slug) && '' !== $slug ? $slug : null;
    }
}
