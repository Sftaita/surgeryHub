<?php

namespace App\Tests\Unit\Notification;

use App\Notification\HtmlToTextRenderer;
use PHPUnit\Framework\TestCase;

/** H5a (§20) — the text/plain part is built from the DOM, never strip_tags(). */
final class HtmlToTextRendererTest extends TestCase
{
    public function test_css_head_and_hidden_preheader_never_reach_the_text_part(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html><html><head><title>Sujet</title><style>body { margin:0; color:#111; } .card{padding:12px}</style></head>
            <body><div style="display:none">Préheader caché</div>
            <h1>Planning publié</h1><p>Bonjour Françoise,<br>votre planning est disponible.</p>
            <script>alert(1)</script><!-- commentaire --></body></html>
            HTML;

        $text = (new HtmlToTextRenderer())->render($html);

        self::assertSame("Planning publié\n\nBonjour Françoise,\nvotre planning est disponible.", $text);
    }

    public function test_lists_tables_links_and_entities_read_naturally(): void
    {
        $html = '<ul><li>Lun 12/10 — <strong>Site A</strong></li><li>Mar 13/10</li></ul>'
            . '<table><tr><td>Date</td><td>12/10/2026</td></tr></table>'
            . '<p><a href="https://app.test/x">Voir la mission</a> · <a href="https://app.test/y">https://app.test/y</a> · <a href="mailto:a@b.c">a@b.c</a></p>'
            . '<p>L&#039;équipe &amp; vous&nbsp;!</p>';

        $text = (new HtmlToTextRenderer())->render($html);

        self::assertStringContainsString("- Lun 12/10 — Site A\n", $text);
        self::assertStringContainsString('- Mar 13/10', $text);
        self::assertStringContainsString('Date 12/10/2026', $text);
        self::assertStringContainsString('Voir la mission (https://app.test/x)', $text);
        self::assertStringContainsString(' https://app.test/y ', $text, 'a URL label is not repeated');
        self::assertStringContainsString('L\'équipe & vous !', $text);
        self::assertStringNotContainsString('<', $text);
    }

    public function test_empty_html_gives_empty_text(): void
    {
        self::assertSame('', (new HtmlToTextRenderer())->render('  '));
    }
}
