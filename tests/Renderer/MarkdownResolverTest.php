<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Tests\Renderer;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Provider\MarkdownRepresentationProviderInterface;
use Koh\SyliusMarkdown\Renderer\MarkdownResolver;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The chain-of-responsibility rules that decide which document an agent gets.
 *
 * Both failure directions are silent in production: a resolver that stops too
 * early serves a half-built document, one that never stops serves HTML to a
 * client that asked for Markdown. Neither raises anything.
 */
final class MarkdownResolverTest extends TestCase
{
    public function testTheFirstProviderThatRendersWins(): void
    {
        $resolver = new MarkdownResolver([
            $this->provider(supports: true, markdown: '# First'),
            $this->provider(supports: true, markdown: '# Second'),
        ]);

        self::assertSame('# First', $resolver->resolve($this->request(), $this->context()));
    }

    public function testAProviderThatDoesNotClaimTheRouteIsSkipped(): void
    {
        $resolver = new MarkdownResolver([
            $this->provider(supports: false, markdown: '# Wrong route'),
            $this->provider(supports: true, markdown: '# Right route'),
        ]);

        self::assertSame('# Right route', $resolver->resolve($this->request(), $this->context()));
    }

    /**
     * Supporting a route and being able to render it are different questions.
     * A provider that claims the route but has nothing for this particular
     * entity must hand over rather than end the chain.
     */
    public function testAClaimingProviderThatRendersNothingFallsThrough(): void
    {
        $resolver = new MarkdownResolver([
            $this->provider(supports: true, markdown: null),
            $this->provider(supports: true, markdown: '# Fallback'),
        ]);

        self::assertSame('# Fallback', $resolver->resolve($this->request(), $this->context()));
    }

    /**
     * A document of pure whitespace is the worst outcome: it is served as a
     * successful 200 and an agent has no way to tell it from a real answer.
     */
    public function testAWhitespaceOnlyDocumentCountsAsNothing(): void
    {
        $resolver = new MarkdownResolver([
            $this->provider(supports: true, markdown: "  \n\t\n "),
            $this->provider(supports: true, markdown: '# Real'),
        ]);

        self::assertSame('# Real', $resolver->resolve($this->request(), $this->context()));
    }

    public function testNothingToRenderYieldsNull(): void
    {
        self::assertNull((new MarkdownResolver([]))->resolve($this->request(), $this->context()));
        self::assertNull(
            (new MarkdownResolver([$this->provider(supports: true, markdown: null)]))
                ->resolve($this->request(), $this->context()),
        );
    }

    /** A later provider must still be reached once an earlier one declines. */
    public function testEveryProviderIsConsultedUntilOneRenders(): void
    {
        $second = $this->provider(supports: true, markdown: '# Second');

        (new MarkdownResolver([$this->provider(supports: true, markdown: null), $second]))
            ->resolve($this->request(), $this->context());

        self::assertSame(1, $second->renderCalls);
    }

    /**
     * supports() decides where `Vary: Accept` goes. Answering true everywhere
     * would set it on pages with no Markdown representation and halve the
     * shared-cache hit rate for the whole storefront.
     */
    public function testSupportsIsTrueOnlyWhenSomeProviderClaimsTheRoute(): void
    {
        self::assertFalse((new MarkdownResolver([]))->supports($this->request()));
        self::assertFalse(
            (new MarkdownResolver([$this->provider(supports: false, markdown: '# x')]))->supports($this->request()),
        );
        self::assertTrue(
            (new MarkdownResolver([
                $this->provider(supports: false, markdown: null),
                $this->provider(supports: true, markdown: '# x'),
            ]))->supports($this->request()),
        );
    }

    /**
     * supports() answers a question about routing, so it must not pay for a
     * render. Rendering here would run a database query and a full template on
     * every HTML response just to decide a cache header.
     */
    public function testSupportsDoesNotRender(): void
    {
        $provider = $this->provider(supports: true, markdown: '# x');

        (new MarkdownResolver([$provider]))->supports($this->request());

        self::assertSame(0, $provider->renderCalls);
    }

    private function request(): Request
    {
        return Request::create('/products/zigarre');
    }

    private function context(): AgentContext
    {
        return new AgentContext(
            $this->createMock(ChannelInterface::class),
            'https://shop.example.com',
            'de_DE',
            ['de_DE'],
        );
    }

    private function provider(bool $supports, ?string $markdown): MarkdownRepresentationProviderInterface
    {
        return new class($supports, $markdown) implements MarkdownRepresentationProviderInterface {
            public int $renderCalls = 0;

            public function __construct(private bool $supports, private ?string $markdown)
            {
            }

            public function supports(Request $request): bool
            {
                return $this->supports;
            }

            public function render(Request $request, AgentContext $context): ?string
            {
                ++$this->renderCalls;

                return $this->markdown;
            }
        };
    }
}
