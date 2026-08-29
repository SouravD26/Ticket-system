<?php
/**
 * A very small PDF writer — just enough for a titled, paginated table.
 *
 * There is no Composer in this project, so rather than pull in a library this builds the
 * handful of PDF objects by hand: one page tree, a Helvetica core font (so nothing has to
 * be embedded), and a content stream per page. Core fonts are WinAnsi, so text is
 * transliterated to Latin-1 on the way in — see enc().
 */
final class SimplePdf
{
    /** Page geometry, in PDF points (72 per inch). A4 landscape. */
    private const W = 842.0;
    private const H = 595.0;
    private const MARGIN = 36.0;

    private array $pages = [];      // finished content streams
    private string $buf = '';       // the page being written
    private float $y = 0.0;
    private int $pageNo = 0;

    private array $cols;            // [label, width, align]
    private string $title;
    private string $subtitle;

    public function __construct(string $title, string $subtitle, array $cols)
    {
        $this->title    = $title;
        $this->subtitle = $subtitle;
        $this->cols     = $cols;
        $this->newPage();
    }

    /** Core fonts speak WinAnsi, so fold anything else down to plain ASCII. */
    private static function enc(string $s): string
    {
        $s = strtr($s, [
            '—' => '-', '–' => '-', '·' => '-', '’' => "'", '‘' => "'",
            '“' => '"', '”' => '"', '…' => '...', '×' => 'x', '→' => '->',
        ]);
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($out === false) $out = preg_replace('/[^\x20-\x7E]/', '?', $s);
        return preg_replace('/[^\x20-\x7E]/', '?', $out);
    }

    private static function esc(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    /** Helvetica is ~0.5em average; close enough to wrap a table cell sensibly. */
    private static function textWidth(string $s, float $size): float
    {
        return strlen($s) * $size * 0.5;
    }

    private function text(float $x, float $y, string $s, float $size = 9.0, bool $bold = false, string $grey = '0'): void
    {
        $s = self::esc(self::enc($s));
        if ($s === '') return;
        $font = $bold ? '/F2' : '/F1';
        $this->buf .= sprintf("BT %s %.1f Tf %s g %.2f %.2f Td (%s) Tj ET\n", $font, $size, $grey, $x, $y, $s);
    }

    private function line(float $x1, float $y1, float $x2, float $y2, string $grey = '0.85'): void
    {
        $this->buf .= sprintf("%s G 0.6 w %.2f %.2f m %.2f %.2f l S\n", $grey, $x1, $y1, $x2, $y2);
    }

    private function rect(float $x, float $y, float $w, float $h, string $grey): void
    {
        $this->buf .= sprintf("%s g %.2f %.2f %.2f %.2f re f\n", $grey, $x, $y, $w, $h);
    }

    private function newPage(): void
    {
        if ($this->buf !== '') $this->pages[] = $this->buf;
        $this->buf = '';
        $this->pageNo++;
        $this->y = self::H - self::MARGIN;

        $this->text(self::MARGIN, $this->y - 12, $this->title, 15, true);
        $this->y -= 30;
        $this->text(self::MARGIN, $this->y, $this->subtitle, 9, false, '0.35');
        $this->y -= 18;
        $this->tableHead();
    }

    private function tableHead(): void
    {
        $this->rect(self::MARGIN, $this->y - 4, self::W - 2 * self::MARGIN, 18, '0.93');
        $x = self::MARGIN + 4;
        foreach ($this->cols as [$label, $w, $align]) {
            $this->text($x, $this->y + 2, $label, 8, true, '0.25');
            $x += $w;
        }
        $this->y -= 10;
        $this->line(self::MARGIN, $this->y, self::W - self::MARGIN, $this->y, '0.75');
        $this->y -= 4;
    }

    /** Wrap one cell's text to its column width. */
    private static function wrap(string $s, float $w, float $size): array
    {
        $s = self::enc($s);
        if ($s === '') return [''];
        $lines = [];
        foreach (preg_split('/\R/', $s) as $para) {
            $cur = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                if ($word === '') continue;
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if (self::textWidth($try, $size) > $w - 8 && $cur !== '') { $lines[] = $cur; $cur = $word; }
                else { $cur = $try; }
            }
            $lines[] = $cur;
        }
        return $lines ?: [''];
    }

    public function row(array $cells, bool $zebra = false): void
    {
        $size = 8.5;
        $wrapped = [];
        $maxLines = 1;
        foreach ($this->cols as $i => [$label, $w, $align]) {
            $wrapped[$i] = self::wrap((string) ($cells[$i] ?? ''), $w, $size);
            $maxLines = max($maxLines, count($wrapped[$i]));
        }
        $height = $maxLines * 11 + 6;

        if ($this->y - $height < self::MARGIN + 24) $this->newPage();

        if ($zebra) $this->rect(self::MARGIN, $this->y - $height + 8, self::W - 2 * self::MARGIN, $height, '0.975');

        $x = self::MARGIN + 4;
        foreach ($this->cols as $i => [$label, $w, $align]) {
            $ty = $this->y;
            foreach ($wrapped[$i] as $ln) {
                $tx = $x;
                if ($align === 'r') $tx = $x + $w - 8 - self::textWidth($ln, $size);
                $this->text($tx, $ty, $ln, $size, false, '0.15');
                $ty -= 11;
            }
            $x += $w;
        }
        $this->y -= $height;
        $this->line(self::MARGIN, $this->y + 6, self::W - self::MARGIN, $this->y + 6, '0.9');
    }

    public function totals(array $cells): void
    {
        if ($this->y - 26 < self::MARGIN + 24) $this->newPage();
        $this->y -= 4;
        $this->rect(self::MARGIN, $this->y - 6, self::W - 2 * self::MARGIN, 20, '0.93');
        $x = self::MARGIN + 4;
        foreach ($this->cols as $i => [$label, $w, $align]) {
            $v = (string) ($cells[$i] ?? '');
            $tx = $align === 'r' ? $x + $w - 8 - self::textWidth(self::enc($v), 9) : $x;
            $this->text($tx, $this->y, $v, 9, true, '0');
            $x += $w;
        }
        $this->y -= 24;
    }

    private function footers(): void
    {
        $total = count($this->pages);
        foreach ($this->pages as $i => $content) {
            $stamp = sprintf('Page %d of %d  -  generated %s', $i + 1, $total, date('M j, Y g:i a'));
            $this->pages[$i] = $content . sprintf(
                "BT /F1 8.0 Tf 0.5 g %.2f %.2f Td (%s) Tj ET\n",
                self::MARGIN, self::MARGIN - 8, self::esc(self::enc($stamp))
            );
        }
    }

    public function output(): string
    {
        if ($this->buf !== '') { $this->pages[] = $this->buf; $this->buf = ''; }
        $this->footers();

        $n = count($this->pages);
        $objects = [];

        // 1 catalog, 2 page tree, 3..(2+n) pages, then contents, then the two fonts
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (3 + $i) . ' 0 R';
        $fontA = 3 + 2 * $n;
        $fontB = $fontA + 1;

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Count $n /Kids [" . implode(' ', $kids) . "] >>";

        for ($i = 0; $i < $n; $i++) {
            $contentObj = 3 + $n + $i;
            $objects[3 + $i] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>",
                self::W, self::H, $fontA, $fontB, $contentObj
            );
            $stream = $this->pages[$i];
            $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }
        $objects[$fontA] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[$fontB] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
