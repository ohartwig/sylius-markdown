<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/sylius-markdown

`Accept: text/markdown` content negotiation for the Sylius storefront.

An agent asking for Markdown gets Markdown; a browser gets the page it always
got.

## Install

```bash
composer require koh/sylius-markdown
```

```php
// config/bundles.php
Koh\SyliusMarkdown\KohSyliusMarkdownBundle::class => ['all' => true],
```

## Configure

```yaml
# config/packages/koh_markdown.yaml
koh_markdown:
    enabled: true
    max_age: 3600
    products: true      # serve Markdown for product pages
```

## Two decisions worth knowing about

### Markdown is built from entities, not from rendered HTML

Converting the finished page is the shortcut, and it produces a document full of
navigation, footer, cookie banner and related-product carousels — furniture an
agent has to discard, if it can tell it apart from the content at all. Each
resource type gets a template over its entity instead:

```
templates/product.md.twig
```

Override it in your application at
`templates/bundles/KohSyliusMarkdownBundle/product.md.twig`.

HTML still appears in one place: rich-text fields are authored as HTML in the
admin. Those single fields go through `league/html-to-markdown` via the
`markdown_from_html` filter — converting a field, not a layout.

### The controller never runs

The listener sits on `kernel.request` and short-circuits with `setResponse()`,
so the controller and its whole Twig render are skipped. The alternative —
render HTML, then throw it away — pays for the full page on every agent hit.

Priority 4 puts it **below** Symfony's firewall (8). Authentication has already
run, so a Markdown request for `/account/…` is subject to the same access
control as the HTML one. Above the firewall it would be an authentication
bypass.

## Negotiation

The naive check (`str_contains($accept, 'text/markdown')`) gets two real cases
wrong, so the header is parsed per RFC 9110:

| Accept | Result | Why |
|---|---|---|
| `text/markdown` | Markdown | |
| `text/markdown;q=1.0, text/html;q=0.9` | Markdown | preferred |
| `text/html;q=1.0, text/markdown;q=0.5` | HTML | merely tolerated |
| `text/markdown;q=0, text/html` | HTML | `q=0` is a rejection |
| `text/html,…,*/*;q=0.8` | HTML | ordinary browser |
| `*/*` | HTML | a wildcard is not a preference |

`*/*` is deliberately not treated as competition either — every browser sends it
in the tail, and counting it would mean no client could ever request Markdown.

## Response

```
Content-Type: text/markdown; charset=utf-8
Vary: Accept
X-Markdown-Tokens: 412
Link: <https://shop.example.com/products/zigarre>; rel="canonical"
Cache-Control: public, max-age=3600
ETag: "…"
```

`Vary: Accept` also goes on the **HTML** side of a negotiable route, via the
shared response-header listener. Without it a shared cache stores one variant
under a key that ignores `Accept` and serves it to clients asking for the other.
It is scoped to routes that actually have a provider — setting it globally
fragments the cache for every page that has no Markdown representation.

## Custom representations

Implement `MarkdownRepresentationProviderInterface` from
`koh/sylius-agent-contracts`; container-wide autoconfiguration picks it up, so a
service in your own `App\` namespace needs no wiring.

```php
final class ArticleMarkdownProvider implements MarkdownRepresentationProviderInterface
{
    public function supports(Request $request): bool
    {
        return 'app_shop_article_show' === $request->attributes->get('_route');
    }

    public function render(Request $request, AgentContext $context): ?string
    {
        // return null to fall through to HTML
    }
}
```

Returning `null` — or whitespace — falls through to the next provider and
ultimately to HTML. An empty Markdown document would look like a successful
answer and hide the gap.

## Decommission

Remove the `composer require`, the `config/bundles.php` entry and
`config/packages/koh_markdown.yaml`. No database table, no route, no cache pool.
Purge any shared cache afterwards so stored Markdown variants expire.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
