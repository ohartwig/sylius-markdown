<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Tests\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusMarkdown\Provider\ProductMarkdownProvider;
use Koh\SyliusMarkdown\Renderer\MarkdownNormalizer;
use Koh\SyliusMarkdown\Twig\MarkdownExtension;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\ProductTranslation;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Product\Model\ProductVariantTranslation;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Exercises the provider through the real shipped template.
 *
 * A stub template would prove the provider calls Twig and nothing else. The
 * document itself is the deliverable here — an agent consumes the table, the
 * converted description and the canonical source line — so the template is part
 * of what has to keep working.
 */
final class ProductMarkdownProviderTest extends TestCase
{
    public function testItClaimsOnlyTheProductShowRoute(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supports($this->request('sylius_shop_product_show', ['slug' => 'zigarre'])));
        self::assertFalse($provider->supports($this->request('sylius_shop_homepage', ['slug' => 'zigarre'])));
    }

    /**
     * The route can match while the slug is absent — a partial match during
     * routing, or a route redefined in the application without the placeholder.
     * Claiming the request then would short-circuit the controller and hand the
     * agent nothing.
     */
    public function testARouteWithoutAUsableSlugIsNotClaimed(): void
    {
        $provider = $this->provider();

        self::assertFalse($provider->supports($this->request('sylius_shop_product_show', [])));
        self::assertFalse($provider->supports($this->request('sylius_shop_product_show', ['slug' => ''])));
        self::assertFalse($provider->supports($this->request('sylius_shop_product_show', ['slug' => 42])));
        self::assertFalse($provider->supports($this->request('sylius_shop_product_show', null)));
    }

    public function testRenderingWithoutASlugYieldsNothing(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneByChannelAndSlug');

        self::assertNull(
            $this->provider($repository)->render($this->request('sylius_shop_product_show', []), $this->context()),
        );
    }

    /** An unknown slug falls through to HTML, which will render the 404. */
    public function testAnUnknownProductYieldsNothing(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findOneByChannelAndSlug')->willReturn(null);

        self::assertNull(
            $this->provider($repository)->render(
                $this->request('sylius_shop_product_show', ['slug' => 'ghost']),
                $this->context(),
            ),
        );
    }

    /**
     * The classic multi-channel bug is looking a product up without the channel
     * and locale from the context: one channel then serves another channel's
     * catalogue under its own hostname.
     */
    public function testTheLookupIsScopedToTheChannelAndLocaleFromTheContext(): void
    {
        $channel = $this->channel();
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findOneByChannelAndSlug')
            ->with(self::identicalTo($channel), 'de_DE', 'zigarre')
            ->willReturn($this->product());

        $markdown = $this->provider($repository)->render(
            $this->request('sylius_shop_product_show', ['slug' => 'zigarre']),
            $this->context($channel),
        );

        self::assertNotNull($markdown);
    }

    public function testTheDocumentCarriesTheProductAndItsVariants(): void
    {
        $markdown = $this->render($this->product());

        self::assertStringContainsString('# Zigarre', $markdown);
        self::assertStringContainsString('> Handgerollt', $markdown);
        self::assertStringContainsString('| Small | CIGAR-1-S | 12,50 EUR | in stock |', $markdown);
    }

    /**
     * Descriptions are authored as HTML in the admin. Shipping that HTML into a
     * Markdown document would make the agent parse two languages at once.
     */
    public function testTheHtmlDescriptionArrivesAsMarkdown(): void
    {
        $markdown = $this->render($this->product());

        self::assertStringContainsString('**kräftig**', $markdown);
        self::assertStringNotContainsString('<strong>', $markdown);
        self::assertStringNotContainsString('<p>', $markdown);
    }

    public function testTheCategoriesAreListed(): void
    {
        self::assertStringContainsString("## Categories\n\n- Zigarren", $this->render($this->product()));
    }

    /**
     * The source line is the document's own identity claim. It is built from
     * the context's channel-derived base URL, never from the request host.
     */
    public function testTheSourceLinkComesFromTheContextNotTheRequestHost(): void
    {
        $request = $this->request('sylius_shop_product_show', ['slug' => 'zigarre']);
        $request->headers->set('Host', 'attacker.example');

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findOneByChannelAndSlug')->willReturn($this->product());

        $markdown = (string) $this->provider($repository)->render($request, $this->context());

        self::assertStringContainsString('Source: https://shop.example.com/products/zigarre', $markdown);
        self::assertStringNotContainsString('attacker.example', $markdown);
    }

    /**
     * A product with nothing but a name must still produce a document rather
     * than a page of empty headings — the template's conditionals are what the
     * normalizer cannot fix afterwards.
     */
    public function testASparseProductRendersWithoutEmptySections(): void
    {
        $product = new Product();
        $product->setCode('BARE-1');
        $product->setCurrentLocale('de_DE');
        $product->setFallbackLocale('de_DE');
        $translation = new ProductTranslation();
        $translation->setLocale('de_DE');
        $translation->setName('Nackt');
        $product->addTranslation($translation);

        $markdown = $this->render($product);

        self::assertStringContainsString('# Nackt', $markdown);
        self::assertStringNotContainsString('## Variants', $markdown);
        self::assertStringNotContainsString('## Description', $markdown);
        self::assertStringNotContainsString('## Categories', $markdown);
    }

    /**
     * Template output is normalised before it leaves the provider.
     *
     * Asserted against a deliberately ragged template rather than the shipped
     * one: that template happens to render tidily already, so checking it would
     * pass with the normalizer removed entirely and prove nothing. An override
     * carries no such guarantee, which is precisely why the provider normalises
     * rather than trusting the template.
     */
    public function testTemplateOutputIsNormalisedBeforeItLeavesTheProvider(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findOneByChannelAndSlug')->willReturn($this->product());

        $twig = new Environment(new ArrayLoader([
            'ragged.md.twig' => "\n\n# {{ product.code }} \n\n\n\n\nTail\n\n\n",
        ]));

        $markdown = (new ProductMarkdownProvider($repository, $twig, new MarkdownNormalizer(), 'ragged.md.twig'))
            ->render($this->request('sylius_shop_product_show', ['slug' => 'zigarre']), $this->context());

        self::assertSame("# CIGAR-1\n\nTail\n", $markdown);
    }

    /** An application overriding the template must actually get its own file. */
    public function testTheTemplateIsConfigurable(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findOneByChannelAndSlug')->willReturn($this->product());

        $twig = new Environment(new ArrayLoader([
            'custom.md.twig' => 'OVERRIDDEN {{ product.code }}',
        ]));

        $provider = new ProductMarkdownProvider($repository, $twig, new MarkdownNormalizer(), 'custom.md.twig');

        self::assertSame(
            "OVERRIDDEN CIGAR-1\n",
            $provider->render($this->request('sylius_shop_product_show', ['slug' => 'zigarre']), $this->context()),
        );
    }

    private function render(ProductInterface $product): string
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findOneByChannelAndSlug')->willReturn($product);

        return (string) $this->provider($repository)->render(
            $this->request('sylius_shop_product_show', ['slug' => 'zigarre']),
            $this->context(),
        );
    }

    private function provider(?ProductRepositoryInterface $repository = null): ProductMarkdownProvider
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/templates', 'KohSyliusMarkdown');

        $twig = new Environment($loader);
        $twig->addExtension(new MarkdownExtension());

        return new ProductMarkdownProvider(
            $repository ?? $this->createMock(ProductRepositoryInterface::class),
            $twig,
            new MarkdownNormalizer(),
        );
    }

    private function request(string $route, ?array $routeParams): Request
    {
        $request = Request::create('/products/zigarre');
        $request->attributes->set('_route', $route);
        if (null !== $routeParams) {
            $request->attributes->set('_route_params', $routeParams);
        }

        return $request;
    }

    private function context(?ChannelInterface $channel = null): AgentContext
    {
        return new AgentContext($channel ?? $this->channel(), 'https://shop.example.com', 'de_DE', ['de_DE']);
    }

    private function channel(): ChannelInterface
    {
        $currency = new Currency();
        $currency->setCode('EUR');

        $channel = new Channel();
        $channel->setCode('WEB_DE');
        $channel->setBaseCurrency($currency);

        return $channel;
    }

    private function product(): ProductInterface
    {
        $product = new Product();
        $product->setCode('CIGAR-1');
        $product->setCurrentLocale('de_DE');
        $product->setFallbackLocale('de_DE');

        $translation = new ProductTranslation();
        $translation->setLocale('de_DE');
        $translation->setName('Zigarre');
        $translation->setShortDescription('Handgerollt');
        $translation->setDescription('<p>Ein <strong>kräftig</strong> gerollter Klassiker.</p><script>alert(1)</script>');
        $product->addTranslation($translation);

        $variant = new ProductVariant();
        $variant->setCode('CIGAR-1-S');
        $variant->setCurrentLocale('de_DE');
        $variant->setFallbackLocale('de_DE');
        $variant->setOnHand(5);
        $variantTranslation = new ProductVariantTranslation();
        $variantTranslation->setLocale('de_DE');
        $variantTranslation->setName('Small');
        $variant->addTranslation($variantTranslation);

        $pricing = new ChannelPricing();
        $pricing->setChannelCode('WEB_DE');
        $pricing->setPrice(1250);
        $variant->addChannelPricing($pricing);

        $product->addVariant($variant);

        $taxon = new Taxon();
        $taxon->setCode('CIGARS');
        $taxon->setCurrentLocale('de_DE');
        $taxon->setFallbackLocale('de_DE');
        $taxon->setName('Zigarren');

        $productTaxon = new ProductTaxon();
        $productTaxon->setProduct($product);
        $productTaxon->setTaxon($taxon);
        $product->addProductTaxon($productTaxon);

        return $product;
    }
}
