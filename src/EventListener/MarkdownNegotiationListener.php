<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\EventListener;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusMarkdown\Negotiation\AcceptParser;
use Koh\SyliusMarkdown\Renderer\MarkdownResolver;
use Koh\SyliusMarkdown\Renderer\TokenEstimator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Answers `Accept: text/markdown` before the controller runs.
 *
 * Priority 4 puts this below Symfony's firewall (8): the security token is
 * already established, so a Markdown request for /account/… is subject to the
 * same access control as the HTML one. Above it, the listener would be an
 * authentication bypass.
 *
 * Short-circuiting via setResponse() is the point — the controller and the
 * whole Twig render never happen. Rendering HTML and throwing it away is the
 * obvious implementation and costs the full page for every agent hit.
 */
final class MarkdownNegotiationListener
{
    public function __construct(
        private readonly AcceptParser $acceptParser,
        private readonly MarkdownResolver $resolver,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly TokenEstimator $tokenEstimator,
        private readonly int $maxAge = 3600,
        private readonly bool $enabled = true,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only safe, cacheable methods. A POST that happens to accept Markdown
        // still has to reach its controller.
        if (!$request->isMethodCacheable()) {
            return;
        }

        if (!$this->acceptParser->wantsMarkdown($request->headers->get('Accept'))) {
            return;
        }

        $context = $this->contextFactory->create();
        $markdown = $this->resolver->resolve($request, $context);

        // No representation: fall through to HTML rather than serve an empty 200.
        if (null === $markdown) {
            return;
        }

        $response = new Response($markdown, Response::HTTP_OK, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Vary' => 'Accept',
            'X-Markdown-Tokens' => (string) $this->tokenEstimator->estimate($markdown),
            // Built from the channel, not from the request. rel="canonical" is a
            // claim about identity, and agents and search engines take it at
            // face value — deriving it from the Host header would let a request
            // name its own canonical. The rest of this package family resolves
            // URLs through AgentContext for the same reason; this line was the
            // exception. Note that it drops the query string, which is normally
            // what a canonical wants.
            'Link' => \sprintf('<%s>; rel="canonical"', $context->url($request->getPathInfo())),
        ]);

        $response->setEtag(hash('xxh128', $markdown));

        // Shared caching is safe only while nothing about the response depends
        // on who asked. This listener sits below the firewall precisely so a
        // provider may cover an access-controlled route — and the moment one
        // does, `public` would let a shared cache hand one admin's document to
        // the next caller under the same URL. A request carrying cookies is the
        // conservative signal for "there is a session behind this".
        //
        // The agent case is unaffected: a crawler sends no cookies and still
        // gets the public, cacheable response this package exists to serve.
        if ($request->cookies->count() > 0) {
            $response->setPrivate();
            $response->headers->set('Vary', 'Accept, Cookie');
        } else {
            $response->setPublic();
            $response->setMaxAge($this->maxAge);
        }

        $response->isNotModified($request);

        $event->setResponse($response);
    }
}
