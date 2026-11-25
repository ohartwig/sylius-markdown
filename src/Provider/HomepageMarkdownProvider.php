<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Provider\MarkdownRepresentationProviderInterface;
use Koh\SyliusMarkdown\Renderer\MarkdownNormalizer;
use Sylius\Component\Core\Model\TaxonInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * Renders the storefront homepage as Markdown: the shop and its top categories.
 *
 * The homepage is where an agent lands first — it is the target of the
 * redirect from the bare origin — and its HTML is mostly furniture: hero,
 * teasers, carousels. What it says that an agent can use is who the shop is
 * and where the catalogue starts, so that is the whole document. Built from
 * the channel and its menu taxon, like the product page is built from the
 * product.
 */
final class HomepageMarkdownProvider implements MarkdownRepresentationProviderInterface
{
    public const ROUTE = 'sylius_shop_homepage';

    private const DESCRIPTION_LENGTH = 200;

    public function __construct(
        private readonly Environment $twig,
        private readonly MarkdownNormalizer $normalizer,
        private readonly string $template = '@KohSyliusMarkdown/homepage.md.twig',
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::ROUTE === $request->attributes->get('_route');
    }

    public function render(Request $request, AgentContext $context): string
    {
        $channel = $context->channel;

        return $this->normalizer->normalize($this->twig->render($this->template, [
            'channel' => $channel,
            'name' => $channel->getName() ?? (string) $channel->getCode(),
            'description' => $this->plain($channel->getDescription()),
            'categories' => $this->categories($context),
            'locale' => $context->localeCode,
            'url' => $context->url('/'.$context->localeCode.'/'),
        ]));
    }

    /**
     * The first level below the menu taxon, as the storefront navigation shows
     * it. Deeper levels belong to the category pages; a flattened tree would
     * turn an entry point into the catalogue.
     *
     * @return list<array{name: string, url: string, description: ?string}>
     */
    private function categories(AgentContext $context): array
    {
        $menuTaxon = $context->channel->getMenuTaxon();
        if (!$menuTaxon instanceof TaxonInterface) {
            return [];
        }

        $categories = [];
        foreach ($menuTaxon->getEnabledChildren() as $taxon) {
            $translation = $taxon->getTranslation($context->localeCode);
            $slug = $translation->getSlug();
            if (null === $slug || '' === $slug) {
                continue;
            }

            $categories[] = [
                'name' => $translation->getName() ?? (string) $taxon->getCode(),
                'url' => $context->url('/'.$context->localeCode.'/taxons/'.$slug),
                'description' => $this->plain($translation->getDescription()),
            ];
        }

        return $categories;
    }

    /** Rich text from the admin, reduced to one bounded line. */
    private function plain(?string $text): ?string
    {
        if (null === $text || '' === trim($text)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strlen($text) > self::DESCRIPTION_LENGTH
            ? mb_substr($text, 0, self::DESCRIPTION_LENGTH - 1).'…'
            : $text;
    }
}
