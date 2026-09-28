<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
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

    /**
     * rel="canonical" is a claim about identity that agents and search engines
     * take at face value, so it must not be derived from a header the caller
     * controls. The context resolves it from the channel instead — which is how
     * the rest of this package family builds URLs.
     */
    public function testTheCanonicalLinkComesFromTheChannelNotTheHostHeader(): void
    {
        $response = $this->dispatch('text/markdown', markdown: '# Zigarre', spoofedHost: 'attacker.example')->getResponse();

        self::assertSame(
            '<https://shop.example.com/products/zigarre>; rel="canonical"',
            $response?->headers->get('Link'),
        );
    }

    /**
     * This listener runs below the firewall so that a provider may cover an
     * access-controlled route. That is exactly why the response must not be
     * marked shared-cacheable when a session is in play: `public` would let a
     * shared cache serve one signed-in user's document to the next caller under
     * the same URL, and `Vary: Accept` alone does not key on the session.
     */
    public function testACookieBearingRequestIsNotSharedCacheable(): void
    {
        $response = $this->dispatch('text/markdown', markdown: '# Konto', withSessionCookie: true)->getResponse();

        self::assertNotNull($response);
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['Accept', 'Cookie'], $response->getVary());
    }

    /**
     * The agent case must stay untouched — a crawler sends no cookies, and
     * serving it a cacheable document is what this package is for.
     */
    public function testAnAnonymousRequestStaysSharedCacheable(): void
    {
        $response = $this->dispatch('text/markdown', markdown: '# Zigarre')->getResponse();

        self::assertNotNull($response);
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertSame(3600, $response->getMaxAge());
        self::assertSame(['Accept'], $response->getVary());
    }

    private function dispatch(
        string $accept,
        ?string $markdown,
        string $method = 'GET',
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
        bool $enabled = true,
        ?string $ifNoneMatch = null,
        bool $withSessionCookie = false,
        ?string $spoofedHost = null,
    ): RequestEvent {
        $request = Request::create('/products/zigarre', $method);
        $request->headers->set('Accept', $accept);
        if (null !== $spoofedHost) {
            $request->headers->set('Host', $spoofedHost);
        }
        if (null !== $ifNoneMatch) {
            $request->headers->set('If-None-Match', $ifNoneMatch);
        }
        if ($withSessionCookie) {
            $request->cookies->set('PHPSESSID', 'abc123');
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
