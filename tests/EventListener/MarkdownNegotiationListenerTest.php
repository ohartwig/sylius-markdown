<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Tests\EventListener;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Provider\MarkdownRepresentationProviderInterface;
use Koh\SyliusMarkdown\EventListener\MarkdownNegotiationListener;
use Koh\SyliusMarkdown\Negotiation\AcceptParser;
use Koh\SyliusMarkdown\Renderer\MarkdownResolver;
use Koh\SyliusMarkdown\Renderer\TokenEstimator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class MarkdownNegotiationListenerTest extends TestCase
{
    /**
     * The whole point: the controller and its Twig render never happen. The
     * obvious implementation renders HTML and throws it away, paying for the
     * full page on every agent hit.
     */
    public function testMarkdownRequestShortCircuitsTheController(): void
    {
        $event = $this->dispatch('text/markdown', markdown: "# Zigarre\n");

        self::assertTrue($event->hasResponse());
        self::assertSame("# Zigarre\n", $event->getResponse()?->getContent());
    }

    public function testResponseCarriesTheAgentFacingHeaders(): void
    {
        $response = $this->dispatch('text/markdown', markdown: str_repeat('a', 400))->getResponse();

        self::assertNotNull($response);
        self::assertSame('text/markdown; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame(['Accept'], $response->getVary());
        self::assertSame('100', $response->headers->get('X-Markdown-Tokens'));
        self::assertStringContainsString('rel="canonical"', (string) $response->headers->get('Link'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    public function testBrowsersAreLeftAlone(): void
    {
        $event = $this->dispatch('text/html,application/xhtml+xml,*/*;q=0.8', markdown: '# Zigarre');

        self::assertFalse($event->hasResponse(), 'an ordinary browser must reach the controller');
    }

    /**
     * A route with no representation falls through to HTML. Serving an empty
     * 200 would look like a successful answer and hide the gap.
     */
    public function testMissingRepresentationFallsThroughToHtml(): void
    {
        $event = $this->dispatch('text/markdown', markdown: null);

        self::assertFalse($event->hasResponse());
    }

    public function testWhitespaceOnlyMarkdownIsTreatedAsMissing(): void
    {
        $event = $this->dispatch('text/markdown', markdown: "  \n\n");

        self::assertFalse($event->hasResponse());
    }

    /** A POST that happens to accept Markdown still has to reach its controller. */
    public function testUnsafeMethodsAreNotIntercepted(): void
    {
        $event = $this->dispatch('text/markdown', markdown: '# x', method: 'POST');

        self::assertFalse($event->hasResponse());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $event = $this->dispatch('text/markdown', markdown: '# x', requestType: HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($event->hasResponse());
    }

    public function testDisablingTheBundleLeavesEveryRequestAlone(): void
    {
        $event = $this->dispatch('text/markdown', markdown: '# x', enabled: false);

        self::assertFalse($event->hasResponse());
    }

    /** Returning agents send If-None-Match; a 304 avoids re-rendering. */
    public function testMatchingEtagYields304(): void
    {
        $etag = $this->dispatch('text/markdown', markdown: '# Zigarre')->getResponse()?->getEtag();
        self::assertNotNull($etag);

        $response = $this->dispatch('text/markdown', markdown: '# Zigarre', ifNoneMatch: $etag)->getResponse();

        self::assertSame(304, $response?->getStatusCode());
    }

    private function dispatch(
        string $accept,
        ?string $markdown,
        string $method = 'GET',
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
        bool $enabled = true,
        ?string $ifNoneMatch = null,
    ): RequestEvent {
        $request = Request::create('/products/zigarre', $method);
        $request->headers->set('Accept', $accept);
        if (null !== $ifNoneMatch) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }

        $listener = new MarkdownNegotiationListener(
            new AcceptParser(),
            new MarkdownResolver([$this->provider($markdown)]),
            $this->contextFactory(),
            new TokenEstimator(),
            3600,
            $enabled,
        );

        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $requestType);
        $listener($event);

        return $event;
    }

    private function provider(?string $markdown): MarkdownRepresentationProviderInterface
    {
        return new class($markdown) implements MarkdownRepresentationProviderInterface {
            public function __construct(private ?string $markdown)
            {
            }

            public function supports(Request $request): bool
            {
                return true;
            }

            public function render(Request $request, AgentContext $context): ?string
            {
                return $this->markdown;
            }
        };
    }

    private function contextFactory(): AgentContextFactoryInterface
    {
        $channel = $this->createMock(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        $factory = $this->createMock(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(
            new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']),
        );

        return $factory;
    }
}
