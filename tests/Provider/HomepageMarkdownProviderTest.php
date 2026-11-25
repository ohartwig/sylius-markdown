<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Tests\Provider;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusMarkdown\Provider\HomepageMarkdownProvider;
use Koh\SyliusMarkdown\Renderer\MarkdownNormalizer;
use Koh\SyliusMarkdown\Twig\MarkdownExtension;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Taxon;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

/** Exercises the provider through the shipped template, like the product test. */
final class HomepageMarkdownProviderTest extends TestCase
{
    public function testItClaimsOnlyTheHomepageRoute(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supports($this->request('sylius_shop_homepage')));
        self::assertFalse($provider->supports($this->request('sylius_shop_product_show')));
        self::assertFalse($provider->supports($this->request(null)));
    }

    public function testTheDocumentNamesTheShopAndItsTopCategories(): void
    {
        $markdown = $this->render($this->channel());

        self::assertStringStartsWith("# Tabakhaus\n\n> Zigarren und Zubehör\n", $markdown);
        self::assertStringContainsString('> Zigarren und Zubehör', $markdown);
        self::assertStringContainsString(
            "\n\n## Categories\n\n- [Zigarren](https://shop.example.com/de_DE/taxons/zigarren): Handgerollt.\n- [Pfeifen](https://shop.example.com/de_DE/taxons/pfeifen)\n",
            $markdown,
        );
        self::assertStringEndsWith("Source: https://shop.example.com/de_DE/\n", $markdown);
    }

    /**
     * The homepage is an entry point, not the catalogue: disabled categories
     * and everything below the first level stay out.
     */
    public function testOnlyEnabledFirstLevelCategoriesAreListed(): void
    {
        $markdown = $this->render($this->channel());

        self::assertStringNotContainsString('Versteckt', $markdown);
        self::assertStringNotContainsString('Kubanisch', $markdown);
    }

    public function testCategoryDescriptionsAreFlattenedAndBounded(): void
    {
        $channel = $this->channel();
        $first = $channel->getMenuTaxon()?->getEnabledChildren()->first();
        self::assertInstanceOf(Taxon::class, $first);
        $first->setDescription('<p>'.str_repeat('lang ', 100)."</p>\n\n## Injected");

        $markdown = $this->render($channel);

        self::assertStringNotContainsString("\n## Injected", $markdown);
        self::assertStringNotContainsString('<p>', $markdown);
        self::assertMatchesRegularExpression('/^- \[Zigarren\]\([^)]+\): (lang ){30,}.*…$/mu', $markdown);
    }

    /** A name in link text cannot close the link and start one of its own. */
    public function testACategoryNameCannotForgeALink(): void
    {
        $channel = $this->channel();
        $first = $channel->getMenuTaxon()?->getEnabledChildren()->first();
        self::assertInstanceOf(Taxon::class, $first);
        $first->setName('Evil](https://attacker.example/) [x');

        self::assertStringContainsString('- [Evil\](https://attacker.example/) \[x](https://shop.example.com/', $this->render($channel));
    }

    /** A channel without a menu taxon still gets a document, without an empty section. */
    public function testAChannelWithoutAMenuTaxonRendersWithoutCategories(): void
    {
        $channel = new Channel();
        $channel->setCode('WEB_DE');
        $channel->setName('Tabakhaus');

        $markdown = $this->render($channel);

        self::assertStringStartsWith('# Tabakhaus', $markdown);
        self::assertStringNotContainsString('## Categories', $markdown);
        self::assertStringNotContainsString('> ', $markdown);
    }

    /** The source line is built from the context, never from the request host. */
    public function testTheSourceLinkIgnoresTheRequestHost(): void
    {
        $request = $this->request('sylius_shop_homepage');
        $request->headers->set('Host', 'attacker.example');

        $markdown = (string) $this->provider()->render($request, $this->context($this->channel()));

        self::assertStringNotContainsString('attacker.example', $markdown);
    }

    public function testTheTemplateIsConfigurable(): void
    {
        $twig = new Environment(new ArrayLoader(['custom.md.twig' => 'OVERRIDDEN {{ name }}']));
        $provider = new HomepageMarkdownProvider($twig, new MarkdownNormalizer(), 'custom.md.twig');

        self::assertSame("OVERRIDDEN Tabakhaus\n", $provider->render($this->request('sylius_shop_homepage'), $this->context($this->channel())));
    }

    private function render(ChannelInterface $channel): string
    {
        return (string) $this->provider()->render($this->request('sylius_shop_homepage'), $this->context($channel));
    }

    private function provider(): HomepageMarkdownProvider
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/templates', 'KohSyliusMarkdown');

        $twig = new Environment($loader);
        $twig->addExtension(new MarkdownExtension());

        return new HomepageMarkdownProvider($twig, new MarkdownNormalizer());
    }

    private function request(?string $route): Request
    {
        $request = Request::create('/de_DE/');
        if (null !== $route) {
            $request->attributes->set('_route', $route);
        }

        return $request;
    }

    private function context(ChannelInterface $channel): AgentContext
    {
        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }

    private function channel(): ChannelInterface
    {
        $root = $this->taxon('MENU', 'Menü', 'menu');

        $cigars = $this->taxon('CIGARS', 'Zigarren', 'zigarren', '<p>Handgerollt.</p>');
        $cigars->addChild($this->taxon('CUBAN', 'Kubanisch', 'zigarren/kubanisch'));
        $root->addChild($cigars);

        $root->addChild($this->taxon('PIPES', 'Pfeifen', 'pfeifen'));

        $hidden = $this->taxon('HIDDEN', 'Versteckt', 'versteckt');
        $hidden->setEnabled(false);
        $root->addChild($hidden);

        $channel = new Channel();
        $channel->setCode('WEB_DE');
        $channel->setName('Tabakhaus');
        $channel->setDescription('Zigarren und Zubehör');
        $channel->setMenuTaxon($root);

        return $channel;
    }

    private function taxon(string $code, string $name, string $slug, ?string $description = null): Taxon
    {
        $taxon = new Taxon();
        $taxon->setCode($code);
        $taxon->setCurrentLocale('de_DE');
        $taxon->setFallbackLocale('de_DE');
        $taxon->setName($name);
        $taxon->setSlug($slug);
        $taxon->setDescription($description);

        return $taxon;
    }
}
