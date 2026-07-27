<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Renderer;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Provider\MarkdownRepresentationProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Finds the first provider that can render this request as Markdown.
 *
 * A provider that supports the route but returns null falls through to the next
 * one, and ultimately to HTML. That matters for partially-populated content: an
 * empty Markdown document is worse than the HTML page it replaced.
 */
final class MarkdownResolver
{
    /** @param iterable<MarkdownRepresentationProviderInterface> $providers */
    public function __construct(private readonly iterable $providers)
    {
    }

    public function resolve(Request $request, AgentContext $context): ?string
    {
        foreach ($this->providers as $provider) {
            if (!$provider->supports($request)) {
                continue;
            }

            $markdown = $provider->render($request, $context);
            if (null !== $markdown && '' !== trim($markdown)) {
                return $markdown;
            }
        }

        return null;
    }

    /**
     * Whether any provider claims this route.
     *
     * Used to decide where `Vary: Accept` goes. Setting it globally would halve
     * the shared-cache hit rate for pages that have no Markdown representation
     * at all.
     */
    public function supports(Request $request): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($request)) {
                return true;
            }
        }

        return false;
    }
}
