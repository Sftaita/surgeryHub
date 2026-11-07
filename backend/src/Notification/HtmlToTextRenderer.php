<?php

namespace App\Notification;

/**
 * H5a (§20) — the text/plain part of an HTML email, built from the parsed document (DOM),
 * never from strip_tags() or a regex on the HTML: `strip_tags()` keeps the content of
 * <style> / <head> (CSS rules ended up in the plain-text part of every templated email).
 *
 *   - <head>, <style>, <script>, <title>, comments and hidden elements (display:none —
 *     preheaders) are dropped;
 *   - block elements become line breaks, <br> a line break, <li> a "- " bullet, table cells
 *     are separated by a space;
 *   - a link keeps its URL: "texte (https://…)" (the URL alone when the text is the URL);
 *   - whitespace collapsed, at most one blank line between blocks.
 *
 * Used whenever an email has no dedicated text template.
 */
final class HtmlToTextRenderer
{
    private const DROPPED = ['head', 'style', 'script', 'title', 'noscript', 'template'];
    private const BLOCKS = [
        'p', 'div', 'table', 'tr', 'tbody', 'thead', 'tfoot', 'section', 'article', 'header', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'blockquote', 'hr', 'center', 'body', 'html',
    ];

    public function render(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $text = $this->walk($document);

        $lines = array_map(static fn (string $l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l) ?? $l), explode("\n", $text));
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function walk(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= preg_replace('/\s+/u', ' ', $child->wholeText) ?? '';
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue; // comments, processing instructions, doctype
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROPPED, true) || self::isHidden($child)) {
                continue;
            }

            $inner = $this->walk($child);
            $out .= match (true) {
                $tag === 'br' => "\n",
                $tag === 'li' => "\n- " . trim($inner) . "\n",
                $tag === 'td', $tag === 'th' => ' ' . $inner . ' ',
                $tag === 'a' => self::link($child, $inner),
                $tag === 'img' => ($alt = trim($child->getAttribute('alt'))) !== '' ? ' ' . $alt . ' ' : '',
                in_array($tag, self::BLOCKS, true) => "\n" . $inner . "\n",
                default => $inner,
            };
        }

        return $out;
    }

    private static function link(\DOMElement $a, string $inner): string
    {
        $href  = trim($a->getAttribute('href'));
        $label = trim($inner);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
            return $inner;
        }
        if (str_starts_with(strtolower($href), 'mailto:')) {
            $href = substr($href, 7);
        }

        return $label === '' || $label === $href ? $href : sprintf('%s (%s)', $label, $href);
    }

    private static function isHidden(\DOMElement $element): bool
    {
        $style = strtolower(str_replace(' ', '', $element->getAttribute('style')));

        return str_contains($style, 'display:none') || $element->hasAttribute('hidden');
    }
}
