<?php

declare(strict_types=1);

namespace ChitChat\Policy;

use ChitChat\Http\ApiException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use InvalidArgumentException;
use Stringable;

/** Shared, bounded renderer for published policies, dialogs and preview. */
final class PolicyMarkdownRenderer implements NodeRendererInterface
{
    public const MAX_BYTES = 65536;
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 30,
            'max_delimiters_per_line' => 500,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        foreach ([Heading::class, Link::class, Image::class, Code::class,
            BlockQuote::class, FencedCode::class, IndentedCode::class] as $node) {
            $environment->addRenderer($node, $this, 100);
        }
        $this->converter = new MarkdownConverter($environment);
    }

    public function convert(string $markdown): string
    {
        self::requireSize($markdown);
        return (string) $this->converter->convert($markdown);
    }

    public static function requireSize(string $markdown): void
    {
        if (!mb_check_encoding($markdown, 'UTF-8') || strlen($markdown) > self::MAX_BYTES) {
            throw new ApiException(400, 'validation_error', 'Policy Markdown must be valid UTF-8 and at most 65536 bytes.');
        }
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string|Stringable
    {
        if ($node instanceof Image) {
            return ''; // Policies never request image resources from a reader's browser.
        }
        if ($node instanceof Heading) {
            return new HtmlElement('h' . max(2, $node->getLevel()), [], $childRenderer->renderNodes($node->children()));
        }
        if ($node instanceof Link) {
            $label = $childRenderer->renderNodes($node->children());
            $url = $node->getUrl();
            if (preg_match('/\A(?:https?:\/\/|mailto:)/i', $url) !== 1
                || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
                return $label;
            }
            return new HtmlElement('a', ['href' => $url, 'rel' => 'noopener noreferrer'], $label);
        }
        if ($node instanceof BlockQuote) {
            return $childRenderer->renderNodes($node->children());
        }
        if ($node instanceof Code || $node instanceof FencedCode || $node instanceof IndentedCode) {
            $literal = htmlspecialchars($node->getLiteral(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return $node instanceof Code ? $literal : new HtmlElement('p', [], $literal);
        }
        throw new InvalidArgumentException('Unsupported policy Markdown node.');
    }
}
