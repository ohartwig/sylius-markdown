<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\EventListener;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Provider\ResponseHeaderContributorInterface;
use Koh\SyliusMarkdown\Renderer\MarkdownResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds `Vary: Accept` to the HTML side of a negotiable route.
 *
 * Without it a shared cache stores the HTML variant under a key that ignores
 * Accept and then serves that HTML to the next client asking for Markdown —
 * and vice versa. The Markdown response sets Vary itself; this covers its
 * counterpart.
 *
 * Scoped to routes that actually have a provider. Setting Vary: Accept globally
 * fragments the cache for every page that has no Markdown representation at all.
 */
final class VaryAcceptContributor implements ResponseHeaderContributorInterface
{
    public function __construct(private readonly MarkdownResolver $resolver)
    {
    }

    public function supports(Request $request, Response $response): bool
    {
        return str_contains($response->headers->get('Content-Type', ''), 'text/html')
            && $this->resolver->supports($request);
    }

    public function contribute(Request $request, Response $response, AgentContext $context): void
    {
        // Merge, never replace: Sylius varies on other headers too.
        $vary = $response->getVary();
        if (!\in_array('Accept', $vary, true)) {
            $vary[] = 'Accept';
            $response->setVary($vary);
        }
    }
}
