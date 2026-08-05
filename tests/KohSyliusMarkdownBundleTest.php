<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Tests;

use Koh\SyliusMarkdown\EventListener\MarkdownNegotiationListener;
use Koh\SyliusMarkdown\EventListener\VaryAcceptContributor;
use Koh\SyliusMarkdown\KohSyliusMarkdownBundle;
use Koh\SyliusMarkdown\Negotiation\AcceptParser;
use Koh\SyliusMarkdown\Provider\ProductMarkdownProvider;
use Koh\SyliusMarkdown\Renderer\MarkdownNormalizer;
use Koh\SyliusMarkdown\Renderer\MarkdownResolver;
use Koh\SyliusMarkdown\Renderer\TokenEstimator;
use Koh\SyliusMarkdown\Twig\MarkdownExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The wiring, exercised through Symfony's real extension machinery.
 *
 * Everything here is configuration, which is exactly why it is worth asserting:
 * a wrong listener priority or a missing tag produces no error at all, just a
 * bundle that quietly does nothing. The negotiation listener in particular has
 * a security-relevant priority, and nothing in the class itself enforces it.
 */
final class KohSyliusMarkdownBundleTest extends TestCase
{
    /** templates/ and config/ sit at the package root, not under src/. */
    public function testThePathResolvesTheShippedTemplatesAndServices(): void
    {
        $path = (new KohSyliusMarkdownBundle())->getPath();

        self::assertFileExists($path.'/config/services.php');
        self::assertFileExists($path.'/templates/product.md.twig');
    }

    public function testTheDefaultsAreTheDocumentedOnes(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->getParameter('koh_markdown.enabled'));
        self::assertSame(3600, $container->getParameter('koh_markdown.max_age'));
    }

    public function testTheConfigurationIsHonoured(): void
    {
        $container = $this->load(['enabled' => false, 'max_age' => 120]);

        self::assertFalse($container->getParameter('koh_markdown.enabled'));
        self::assertSame(120, $container->getParameter('koh_markdown.max_age'));
    }

    #[DataProvider('services')]
    public function testTheBundleRegistersItsServices(string $id): void
    {
        self::assertTrue($this->load([])->hasDefinition($id), $id.' is not registered');
    }

    /** @return iterable<string, array{string}> */
    public static function services(): iterable
    {
        yield 'accept parser' => [AcceptParser::class];

        yield 'token estimator' => [TokenEstimator::class];

        yield 'normalizer' => [MarkdownNormalizer::class];

        yield 'resolver' => [MarkdownResolver::class];

        yield 'twig extension' => [MarkdownExtension::class];

        yield 'product provider' => [ProductMarkdownProvider::class];

        yield 'negotiation listener' => [MarkdownNegotiationListener::class];

        yield 'vary contributor' => [VaryAcceptContributor::class];
    }

    /**
     * Priority 4 sits below Symfony's firewall at 8, so the security token is
     * already established and a Markdown request for an account page is subject
     * to the same access control as the HTML one. Raising this above the
     * firewall turns the bundle into an authentication bypass, and neither the
     * container nor the listener would complain.
     */
    public function testTheListenerRunsBelowTheFirewall(): void
    {
        $tags = $this->load([])->getDefinition(MarkdownNegotiationListener::class)->getTag('kernel.event_listener');

        self::assertCount(1, $tags);
        self::assertSame('kernel.request', $tags[0]['event']);
        self::assertSame('__invoke', $tags[0]['method']);
        self::assertLessThan(8, $tags[0]['priority'], 'above the firewall this listener is an authentication bypass');
    }

    /** The tags are how the resolver and the shared header listener find these. */
    public function testTheProviderAndContributorAreTagged(): void
    {
        $container = $this->load([]);

        self::assertNotEmpty($container->getDefinition(ProductMarkdownProvider::class)->getTag('koh_agent.markdown'));
        self::assertNotEmpty(
            $container->getDefinition(VaryAcceptContributor::class)->getTag('koh_agent.response_header'),
        );
        self::assertNotEmpty($container->getDefinition(MarkdownExtension::class)->getTag('twig.extension'));
    }

    /**
     * Turning products off must remove the service, not merely stop it being
     * used. A registered provider stays in the tagged iterator and keeps
     * answering, which is the opposite of what the switch promises.
     */
    public function testDisablingProductsRemovesTheProvider(): void
    {
        $container = $this->load(['products' => false]);

        self::assertFalse($container->hasDefinition(ProductMarkdownProvider::class));
        self::assertTrue($container->hasDefinition(MarkdownResolver::class), 'the rest of the bundle stays');
    }

    public function testProductsAreServedUnlessTurnedOff(): void
    {
        self::assertTrue($this->load([])->hasDefinition(ProductMarkdownProvider::class));
        self::assertTrue($this->load(['products' => true])->hasDefinition(ProductMarkdownProvider::class));
    }

    /**
     * The config tree is a contract with the application. A typo in a shop's
     * config must fail the build rather than silently fall back to a default.
     */
    public function testAMistypedOptionIsRejected(): void
    {
        try {
            $this->load(['max_age' => 'forever']);
            self::fail('a non-integer max_age must not be accepted');
        } catch (InvalidTypeException $e) {
            self::assertStringContainsString('max_age', $e->getMessage());
        }
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        // ContainerConfigurator reads these while importing config/services.php.
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new KohSyliusMarkdownBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        self::assertSame('koh_markdown', $extension->getAlias());

        $extension->load([$config], $container);

        return $container;
    }
}
