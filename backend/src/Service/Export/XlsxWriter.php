<?php

namespace App\Service\Export;

/**
 * D-133 — générateur .xlsx minimal (Office Open XML SpreadsheetML, une feuille) : un vrai
 * classeur zip, pas un CSV renommé. Aucune dépendance hors ext-zip (présente en local et
 * dans l'image Docker). Volontairement limité à ce dont les exports ont besoin : texte,
 * nombres, gras, format monétaire, largeurs de colonnes.
 *
 * Cellule = string|int|float|null, ou ['v' => valeur, 's' => self::STYLE_*].
 */
final class XlsxWriter
{
    public const STYLE_DEFAULT = 0;
    public const STYLE_BOLD = 1;
    public const STYLE_MONEY = 2;
    public const STYLE_BOLD_MONEY = 3;
    public const STYLE_HEADER = 4;

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, float>             $columnWidths largeur par index de colonne (0-based)
     */
    public function build(string $sheetName, array $rows, array $columnWidths = []): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create the xlsx archive.');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>
XML);
        $zip->addFromString('docProps/core.xml', sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>SurgicalHub</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">%s</dcterms:created></cp:coreProperties>
XML, gmdate('Y-m-d\TH:i:s\Z')));
        $zip->addFromString('xl/workbook.xml', sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="%s" sheetId="1" r:id="rId1"/></sheets></workbook>
XML, $this->escape(mb_substr($sheetName, 0, 31))));
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>
XML);
        $zip->addFromString('xl/styles.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE3EAF5"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($rows, $columnWidths));
        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    private function sheet(array $rows, array $columnWidths): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($columnWidths !== []) {
            $xml .= '<cols>';
            foreach ($columnWidths as $index => $width) {
                $xml .= sprintf('<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $index + 1, number_format((float) $width, 1, '.', ''));
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach (array_values($rows) as $r => $row) {
            $xml .= sprintf('<row r="%d">', $r + 1);
            foreach (array_values($row) as $c => $cell) {
                $style = self::STYLE_DEFAULT;
                if (is_array($cell)) {
                    $style = (int) ($cell['s'] ?? self::STYLE_DEFAULT);
                    $cell = $cell['v'] ?? null;
                }
                if ($cell === null || $cell === '') {
                    if ($style !== self::STYLE_DEFAULT) {
                        $xml .= sprintf('<c r="%s" s="%d"/>', $this->ref($c, $r), $style);
                    }
                    continue;
                }
                $ref = $this->ref($c, $r);
                if (is_int($cell) || is_float($cell)) {
                    $xml .= sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, $style, rtrim(rtrim(sprintf('%.6F', $cell), '0'), '.'));
                } else {
                    $xml .= sprintf('<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $ref, $style, $this->escape((string) $cell));
                }
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private function ref(int $column, int $row): string
    {
        $letters = '';
        $n = $column + 1;
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $n = intdiv($n - 1, 26);
        }

        return $letters . ($row + 1);
    }

    private function escape(string $value): string
    {
        // Caractères de contrôle interdits en XML 1.0 retirés, puis échappement standard.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
