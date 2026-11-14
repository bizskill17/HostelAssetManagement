<?php
/**
 * Minimal .xlsx writer and reader for the opening stock bulk upload (no extra libraries:
 * the zip container is written and read here with zlib).
 */
declare(strict_types=1);

function xEsc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xCol(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** Builds a zip file from [name => contents]. */
function zipBuild(array $files): string
{
    $data = '';
    $dir = '';
    foreach ($files as $name => $body) {
        $crc = crc32($body);
        $comp = gzdeflate($body, 6);
        $off = strlen($data);
        $head = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0x21, $crc, strlen($comp), strlen($body), strlen($name), 0);
        $data .= $head . $name . $comp;
        $dir .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0x21, $crc, strlen($comp), strlen($body), strlen($name), 0, 0, 0, 0, 0, $off) . $name;
    }
    return $data . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($dir), strlen($data), 0);
}

/** Reads a zip file into [name => contents], using the central directory. */
function zipRead(string $zip): array
{
    $end = strrpos($zip, "PK\x05\x06");
    if ($end === false) {
        throw new RuntimeException('Not a zip file');
    }
    $e = unpack('Vsig/vdisk/vstart/vn/vtotal/Vsize/Voffset', substr($zip, $end, 20));
    $p = $e['offset'];
    $out = [];
    for ($k = 0; $k < $e['total']; $k++) {
        $c = unpack('Vsig/vver/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vint/Vext/Voff', substr($zip, $p, 46));
        $name = substr($zip, $p + 46, $c['nlen']);
        $p += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
        $l = unpack('Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($zip, $c['off'], 30));
        $raw = substr($zip, $c['off'] + 30 + $l['nlen'] + $l['elen'], $c['csize']);
        $out[$name] = $c['method'] === 8 ? (string) gzinflate($raw) : $raw;
    }
    return $out;
}

/**
 * An .xlsx with one sheet to fill in and a hidden "Lists" sheet. $dropdowns maps a column index of the
 * first sheet to a list of choices, shown as a dropdown; $dateCols are formatted dd/mm/yyyy.
 */
function xlsxTemplate(array $headers, array $dropdowns, array $dateCols, int $rows = 1000): string
{
    // Lists sheet: one column per dropdown.
    $listCols = array_values($dropdowns);
    $max = $listCols ? max(array_map('count', $listCols)) : 0;
    $lr = [];
    for ($r = 0; $r < $max; $r++) {
        $cells = '';
        foreach ($listCols as $ci => $vals) {
            if (isset($vals[$r])) {
                $cells .= '<c r="' . xCol($ci) . ($r + 1) . '" t="inlineStr"><is><t>' . xEsc((string) $vals[$r]) . '</t></is></c>';
            }
        }
        $lr[] = '<row r="' . ($r + 1) . '">' . $cells . '</row>';
    }
    $lists = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $lr) . '</sheetData></worksheet>';

    $head = '';
    foreach ($headers as $i => $h) {
        $head .= '<c r="' . xCol($i) . '1" t="inlineStr" s="1"><is><t>' . xEsc($h) . '</t></is></c>';
    }
    $cols = '';
    foreach ($headers as $i => $h) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(14, min(40, mb_strlen($h) + 6)) . '" customWidth="1"' . (in_array($i, $dateCols, true) ? ' style="2"' : '') . '/>';
    }
    $dv = '';
    $n = 0;
    foreach ($dropdowns as $col => $vals) {
        if (!$vals) {
            $n++;
            continue;
        }
        $L = xCol($n++);
        $dv .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Choose from the list" error="Pick a value from the dropdown." sqref="' . xCol($col) . '2:' . xCol($col) . $rows . '"><formula1>Lists!$' . $L . '$1:$' . $L . '$' . count($vals) . '</formula1></dataValidation>';
    }
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>' . $cols . '</cols><sheetData><row r="1">' . $head . '</row></sheetData>'
        . ($dv ? '<dataValidations count="' . substr_count($dv, '<dataValidation ') . '">' . $dv . '</dataValidations>' : '') . '</worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE0E8FF"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    return zipBuild([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Opening stock" sheetId="1" r:id="rId1"/><sheet name="Lists" sheetId="2" state="hidden" r:id="rId2"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $sheet,
        'xl/worksheets/sheet2.xml' => $lists,
        'xl/styles.xml' => $styles,
    ]);
}

/**
 * Rows of the first sheet of an .xlsx, as arrays of cell text (row 1 included). Cells in $dateCols that hold
 * Excel date numbers come back as yyyy-mm-dd.
 */
function xlsxRows(string $bin, array $dateCols = []): array
{
    $f = zipRead($bin);
    $shared = [];
    if (isset($f['xl/sharedStrings.xml'])) {
        $sx = simplexml_load_string($f['xl/sharedStrings.xml']);
        foreach ($sx->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string) $si->t;
            } else {
                foreach ($si->r as $run) {
                    $t .= (string) $run->t;
                }
            }
            $shared[] = $t;
        }
    }
    // The first sheet listed in the workbook.
    $path = 'xl/worksheets/sheet1.xml';
    if (isset($f['xl/workbook.xml'], $f['xl/_rels/workbook.xml.rels'])) {
        $wb = simplexml_load_string($f['xl/workbook.xml']);
        $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach (simplexml_load_string($f['xl/_rels/workbook.xml.rels'])->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $t = ltrim((string) $rel['Target'], '/');
                $path = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
            }
        }
    }
    if (!isset($f[$path])) {
        throw new RuntimeException('No sheet found');
    }
    $ws = simplexml_load_string($f[$path]);
    $rows = [];
    foreach ($ws->sheetData->row as $row) {
        $vals = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
            $ci = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) {
                $ci = $ci * 26 + (ord($ch) - 64);
            }
            $ci--;
            $type = (string) $c['t'];
            $v = $type === 's' ? ($shared[(int) $c->v] ?? '') : ($type === 'inlineStr' ? (string) $c->is->t : (string) $c->v);
            if (in_array($ci, $dateCols, true) && $type !== 's' && $type !== 'inlineStr' && is_numeric($v)) {
                $v = gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
            }
            $vals[$ci] = trim($v);
        }
        if ($vals) {
            $n = max(array_keys($vals));
            $rows[(int) $row['r']] = array_map(fn($i) => $vals[$i] ?? '', range(0, $n));
        }
    }
    ksort($rows);
    return array_values($rows);
}
