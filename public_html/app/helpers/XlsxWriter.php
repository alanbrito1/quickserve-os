<?php
/**
 * app/helpers/XlsxWriter.php
 * Generador de archivos .xlsx sin dependencias externas.
 * Usa ZipArchive (integrado en PHP 5.2+) para construir el contenedor OOXML.
 *
 * CARACTERÍSTICAS:
 *   - Encabezados en negrita (addRow($cells, header: true))
 *   - Filas de totales con fondo gris (addRow($cells, total: true))
 *   - Congelado automático de primera fila si es encabezado
 *   - Auto-filter en fila de encabezado
 *   - Ancho de columna ajustado al contenido
 *   - Números almacenados como valores numéricos (sumables en Excel)
 *   - UTF-8 correcto para tildes y ñ
 *   - Múltiples hojas mediante instancias separadas (ver addSheet())
 *
 * USO BÁSICO:
 *   $w = new XlsxWriter();
 *   $w->addRow(['Fecha', 'Cliente', 'Total'], header: true);
 *   $w->addRow(['2026-01-01', 'Ana', 18500]);
 *   $w->addRow(['', 'TOTAL', 18500], total: true);
 *   $w->download('reporte.xlsx');
 *
 * USO CON MÚLTIPLES HOJAS:
 *   $w = new XlsxWriter();
 *   $w->setSheet('Ventas');
 *   $w->addRow(['Fecha','Total'], header: true);
 *   $w->addRow(['2026-01-01', 8500]);
 *   $w->setSheet('Inventario');
 *   $w->addRow(['Insumo','Stock'], header: true);
 *   $w->addRow(['Pollo', 3.5]);
 *   $w->download('reporte_completo.xlsx');
 */

class XlsxWriter
{
    // Hojas: ['nombre' => ['rows' => [], 'colWidths' => []]]
    private array  $sheets      = [];
    private string $activeSheet = 'Hoja1';

    public function __construct(string $defaultSheet = 'Datos')
    {
        $this->setSheet($defaultSheet);
    }

    /**
     * Activa (o crea) una hoja. Las siguientes llamadas a addRow() van a esta hoja.
     */
    public function setSheet(string $name): void
    {
        $this->activeSheet = $name;
        if (!isset($this->sheets[$name])) {
            $this->sheets[$name] = ['rows' => [], 'colWidths' => []];
        }
    }

    /**
     * Agrega una fila a la hoja activa.
     *
     * @param array $cells   Valores: string|int|float|null
     * @param bool  $header  Negrita — usar para la primera fila
     * @param bool  $total   Negrita + fondo gris — usar para filas de totales
     */
    public function addRow(array $cells, bool $header = false, bool $total = false): void
    {
        $sheet = &$this->sheets[$this->activeSheet];
        $sheet['rows'][] = compact('cells', 'header', 'total');

        // Calcular ancho de columna óptimo basado en el contenido
        foreach ($cells as $i => $v) {
            $len = mb_strlen((string)$v) + 2;
            $sheet['colWidths'][$i] = max($sheet['colWidths'][$i] ?? 8, min($len, 55));
        }
    }

    /** Agrega una fila en blanco (separador visual). */
    public function addEmptyRow(): void
    {
        $this->sheets[$this->activeSheet]['rows'][] = [
            'cells' => [], 'header' => false, 'total' => false,
        ];
    }

    // ── Salida ───────────────────────────────────────────────────────────────

    /** Descarga el .xlsx al navegador y termina la ejecución. */
    public function download(string $filename)
    {
        if (strtolower(substr($filename, -5)) !== '.xlsx') {
            $filename .= '.xlsx';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cd_xl_');
        $this->save($tmp);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store, no-cache');
        header('Pragma: no-cache');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /** Guarda el .xlsx en una ruta del servidor. */
    public function save(string $path): void
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Extensión ZipArchive no disponible en este servidor.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("No se pudo crear el archivo .xlsx en: $path");
        }

        $sheetNames = array_keys($this->sheets);

        $zip->addFromString('[Content_Types].xml',        $this->xmlContentTypes($sheetNames));
        $zip->addFromString('_rels/.rels',                $this->xmlRels());
        $zip->addFromString('xl/workbook.xml',            $this->xmlWorkbook($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xmlWorkbookRels($sheetNames));
        $zip->addFromString('xl/styles.xml',              $this->xmlStyles());

        foreach ($sheetNames as $idx => $name) {
            $sheetNum = $idx + 1;
            $zip->addFromString(
                "xl/worksheets/sheet{$sheetNum}.xml",
                $this->xmlSheet($this->sheets[$name])
            );
        }

        $zip->close();
    }

    // ── XML builders ─────────────────────────────────────────────────────────

    private function xmlContentTypes(array $sheetNames): string
    {
        $overrides = '';
        foreach ($sheetNames as $idx => $_) {
            $n = $idx + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" "
                        . "ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml"  ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function xmlRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function xmlWorkbook(array $sheetNames): string
    {
        $sheets = '';
        foreach ($sheetNames as $idx => $name) {
            $n    = $idx + 1;
            $safe = htmlspecialchars($name, ENT_XML1, 'UTF-8');
            $sheets .= "<sheet name=\"{$safe}\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets>{$sheets}</sheets>"
            . '</workbook>';
    }

    private function xmlWorkbookRels(array $sheetNames): string
    {
        $rels = '';
        foreach ($sheetNames as $idx => $_) {
            $n = $idx + 1;
            $rels .= "<Relationship Id=\"rId{$n}\" "
                   . "Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" "
                   . "Target=\"worksheets/sheet{$n}.xml\"/>";
        }
        $stylesId = count($sheetNames) + 1;
        $rels .= "<Relationship Id=\"rId{$stylesId}\" "
               . "Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" "
               . "Target=\"styles.xml\"/>";

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private function xmlStyles(): string
    {
        // Estilos: 0=normal, 1=negrita(encabezado), 2=negrita+fondo gris(total)
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            .   '<font><sz val="11"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FFD0D0D0"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function xmlSheet(array $sheetData): string
    {
        $rows      = $sheetData['rows'];
        $colWidths = $sheetData['colWidths'];

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Definir anchos de columna
        if (!empty($colWidths)) {
            $xml .= '<cols>';
            foreach ($colWidths as $ci => $w) {
                $col = $ci + 1;
                $xml .= "<col min=\"{$col}\" max=\"{$col}\" width=\"{$w}\" customWidth=\"1\"/>";
            }
            $xml .= '</cols>';
        }

        // Freeze pane si la primera fila es encabezado
        $hasHeader = !empty($rows) && $rows[0]['header'];
        if ($hasHeader) {
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                 .  '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                 .  '</sheetView></sheetViews>';
        }

        $xml .= '<sheetData>';

        foreach ($rows as $ri => $row) {
            $rowNum    = $ri + 1;
            $styleIdx  = 0;
            if ($row['header']) $styleIdx = 1;
            if ($row['total'])  $styleIdx = 2;

            if (empty($row['cells'])) {
                $xml .= "<row r=\"{$rowNum}\"/>";
                continue;
            }

            $xml .= "<row r=\"{$rowNum}\">";
            foreach ($row['cells'] as $ci => $val) {
                $ref = $this->colLetter($ci) . $rowNum;

                if ($val === null || $val === '') {
                    $xml .= "<c r=\"{$ref}\" s=\"{$styleIdx}\"/>";
                } elseif (is_numeric($val) && !is_string($val)) {
                    // Número nativo — Excel puede sumarlos
                    $xml .= "<c r=\"{$ref}\" s=\"{$styleIdx}\"><v>" . (float)$val . "</v></c>";
                } else {
                    // Cadena inline (UTF-8, escapada para XML)
                    $safe = htmlspecialchars(
                        mb_substr((string)$val, 0, 32767), // límite de Excel
                        ENT_XML1 | ENT_QUOTES, 'UTF-8'
                    );
                    $xml .= "<c r=\"{$ref}\" t=\"inlineStr\" s=\"{$styleIdx}\">"
                          . "<is><t xml:space=\"preserve\">{$safe}</t></is></c>";
                }
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        // Auto-filter en encabezado
        if ($hasHeader && !empty($rows[0]['cells'])) {
            $lastCol = $this->colLetter(count($rows[0]['cells']) - 1);
            $xml .= "<autoFilter ref=\"A1:{$lastCol}1\"/>";
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    /**
     * Convierte índice de columna base-0 a letra(s) de Excel.
     * 0 → A, 25 → Z, 26 → AA …
     */
    private function colLetter(int $col): string
    {
        $letter = '';
        do {
            $letter = chr(65 + ($col % 26)) . $letter;
            $col    = intdiv($col, 26) - 1;
        } while ($col >= 0);
        return $letter;
    }
}
