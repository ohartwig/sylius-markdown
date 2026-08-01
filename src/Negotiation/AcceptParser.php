<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusMarkdown\Negotiation;

/**
 * Decides whether a client actually asked for Markdown.
 *
 * Written out rather than delegated to a generic negotiator because the naive
 * check — str_contains($accept, 'text/markdown') — gets two cases wrong that
 * both occur in the wild: a q=0 rejection reads as a request, and a client that
 * merely tolerates Markdown behind HTML gets served Markdown.
 */
final class AcceptParser
{
    public const MARKDOWN = 'text/markdown';

    /**
     * True when Markdown is at least as preferred as every concrete alternative.
     *
     * `*​/*` is deliberately not counted as an alternative: every browser sends
     * it in the tail of its Accept header, and treating it as competition would
     * mean no browser could ever get Markdown even when asking for it. It is
     * also not counted as a *request* for Markdown — a wildcard is not a
     * preference.
     */
    public function wantsMarkdown(?string $accept): bool
    {
        if (null === $accept || '' === trim($accept)) {
            return false;
        }

        $markdownQuality = 0.0;
        $bestOtherQuality = 0.0;

        foreach ($this->parse($accept) as [$type, $quality]) {
            if (0 === strcasecmp($type, self::MARKDOWN)) {
                $markdownQuality = max($markdownQuality, $quality);

                continue;
            }

            if ('*/*' !== $type) {
                $bestOtherQuality = max($bestOtherQuality, $quality);
            }
        }

        return $markdownQuality > 0.0 && $markdownQuality >= $bestOtherQuality;
    }

    /**
     * @return list<array{string, float}> media type and its q value
     */
    private function parse(string $accept): array
    {
        $entries = [];

        foreach (explode(',', $accept) as $part) {
            $params = explode(';', trim($part));
            $type = strtolower(trim(array_shift($params)));

            if ('' === $type) {
                continue;
            }

            $quality = 1.0;
            foreach ($params as $param) {
                $param = trim($param);
                if (str_starts_with(strtolower($param), 'q=')) {
                    $quality = $this->clamp((float) substr($param, 2));
                }
            }

            $entries[] = [$type, $quality];
        }

        return $entries;
    }

    private function clamp(float $quality): float
    {
        return max(0.0, min(1.0, $quality));
    }
}
