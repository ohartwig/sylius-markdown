<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Tests\EventListener;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Provider\MarkdownRepresentationProviderInterface;
use Koh\SyliusMarkdown\EventListener\VaryAcceptContributor;
use Koh\SyliusMarkdown\Renderer\MarkdownResolver;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTML half of the negotiation, which is where cache poisoning happens.
 *
 * The Markdown response sets `Vary: Accept` itself. Without the same header on
 * the HTML variant a shared cache stores HTML under a key that ignores Accept
 * and then serves it to the next client asking for Markdown -- a bug that only
 * appears behind a cache, i.e. never in development.
 */
final class VaryAcceptContributorTest extends TestCase
{
    public function testItCoversAnHtmlResponseOnANegotiableRoute(): void
    {
        self::assertTrue(
            $this->contributor(hasProvider: true)->supports($this->request(), $this->response('text/html; charset=UTF-8')),
        );
    }

    /**
     * Scoped to routes that actually have a provider. Setting Vary: Accept
     * everywhere fragments the shared cache for every page that has no Markdown
     * representation at all.
     */
    public function testARouteWithNoProviderIsLeftAlone(): void
    {
        self::assertFalse(
            $this->contributor(hasProvider: false)->supports($this->request(), $this->response('text/html')),
        );
    }

    /**
     * Only the HTML variant needs the counterpart header. A JSON API response
     * does not participate in this negotiation, and varying it on Accept splits
     * its cache entries for nothing.
     */
    public function testNonHtmlResponsesAreLeftAlone(): void
    {
        $contributor = $this->contributor(hasProvider: true);

        self::assertFalse($contributor->supports($this->request(), $this->response('application/json')));
        self::assertFalse($contributor->supports($this->request(), $this->response('text/markdown; charset=utf-8')));
    }

    public function testAResponseWithoutAContentTypeIsLeftAlone(): void
    {
        $response = new Response('');
        $response->headers->remove('Content-Type');

        self::assertFalse($this->contributor(hasProvider: true)->supports($this->request(), $response));
    }

    public function testItAddsVaryAccept(): void
    {
        $response = $this->response('text/html');

        $this->contributor(hasProvider: true)->contribute($this->request(), $response, $this->context());

        self::assertSame(['Accept'], $response->getVary());
    }

    /**
     * Merge, never replace. Sylius varies the storefront on other headers too,
     * and dropping one of those is a correctness bug that a cache hides until
     * it serves the wrong currency or locale to somebody.
     */
    public function testExistingVaryHeadersSurvive(): void
    {
        $response = $this->response('text/html');
        $response->setVary(['Accept-Language', 'Cookie']);

        $this->contributor(hasProvider: true)->contribute($this->request(), $response, $this->context());

        self::assertSame(['Accept-Language', 'Cookie', 'Accept'], $response->getVary());
    }

    /** Contributors can run more than once per response; the header must not grow. */
    public function testAcceptIsNotAddedTwice(): void
    {
        $contributor = $this->contributor(hasProvider: true);
        $response = $this->response('text/html');

        $contributor->contribute($this->request(), $response, $this->context());
        $contributor->contribute($this->request(), $response, $this->context());

        self::assertSame(['Accept'], $response->getVary());
    }

    private function contributor(bool $hasProvider): VaryAcceptContributor
    {
        $providers = $hasProvider
            ? [new class implements MarkdownRepresentationProviderInterface {
                public function supports(Request $request): bool
                {
                    return true;
                }

                public function render(Request $request, AgentContext $context): ?string
                {
                    return '# Zigarre';
                }
            }]
            : [];

        return new VaryAcceptContributor(new MarkdownResolver($providers));
    }

    private function request(): Request
    {
        return Request::create('/products/zigarre');
    }

    private function response(string $contentType): Response
    {
        return new Response('<html></html>', Response::HTTP_OK, ['Content-Type' => $contentType]);
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
}
