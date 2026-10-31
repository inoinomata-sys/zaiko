<?php
declare(strict_types=1);

// PDF の作成（Flask 版の fpdf2 と同じレイアウトを、tFPDF で作る）
// 日本語フォントは storage/pdffont/unifont/NotoSansJP-Regular.ttf（デプロイ時に置かれる）。
// 最初の1回だけ、フォントの情報を読み取って storage/pdffont/unifont/ に控えを作るので、少し時間がかかる。

@ini_set('memory_limit', '512M');
@set_time_limit(120);

if (!defined('FPDF_FONTPATH')) {
    define('FPDF_FONTPATH', BASE_DIR . '/storage/pdffont/');
}

const PDF_FONT = 'NotoSans';
const PDF_COLS = 3;

class ZPdf extends tFPDF
{
    public string $titleText = '';
    private array $widthCache = [];

    public function __construct(string $format = 'A4')
    {
        self::dropForeignFontCache();
        parent::__construct('P', 'mm', $format);
        $this->AddFont(PDF_FONT, '', 'NotoSansJP-Regular.ttf', true);
        $this->SetFont(PDF_FONT, '', 12);
        $this->AliasNbPages();
        $this->SetLineWidth(0.25);
    }

    /** 2ページ目からの見出し。同梱フォントが極細なので、文字に輪郭線を重ねて太く見せる（Tr 2 = 塗り＋輪郭） */
    public function Header(): void
    {
        $this->_out('2 Tr');
        if ($this->PageNo() > 1) {
            $this->SetFont(PDF_FONT, '', 10);
            $this->Cell(0, 6, $this->titleText, 0, 1);
            $this->Ln(1);
        }
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont(PDF_FONT, '', 10);
        $this->Cell(0, 6, $this->PageNo() . ' / {nb} ページ', 0, 0, 'C');
    }

    /**
     * フォントの情報の控え（.mtx.php など）には、作った場所の道が書かれる。自動テストの場所（/home/runner/…）で
     * 作られた控えがロリポップに置かれると、フォントが見つからないエラーになるので、見つけたら消して作り直させる
     */
    private static function dropForeignFontCache(): void
    {
        $dir = BASE_DIR . '/storage/pdffont/unifont/';
        $mtx = $dir . 'notosansjp-regular.mtx.php';
        if (is_file($mtx) && str_contains((string)@file_get_contents($mtx), '/home/runner/')) {
            foreach (glob($dir . 'notosansjp-regular.*') ?: [] as $f) {
                if (!str_ends_with($f, '.ttf')) {
                    @unlink($f);
                }
            }
        }
    }

    // tFPDF の用紙の幅・高さ・余白は外から読めないので、読むための窓口を用意する
    public function pageW(): float
    {
        return (float)$this->w;
    }

    public function pageH(): float
    {
        return (float)$this->h;
    }

    public function leftM(): float
    {
        return (float)$this->lMargin;
    }

    public function rightM(): float
    {
        return (float)$this->rMargin;
    }

    private function charWidth(string $ch): float
    {
        $key = $this->FontSizePt . '|' . $ch;
        return $this->widthCache[$key] ??= (float)$this->GetStringWidth($ch);
    }

    /** 文字を幅に合わせて折り返した行の並び（空白で折り返せる時は空白で。なければ文字の途中で） */
    public function wrapLines(float $w, string $txt): array
    {
        $wmax = $w - 2 * $this->cMargin;
        $out = [];
        foreach (explode("\n", str_replace("\r", '', $txt)) as $para) {
            $buf = [];
            $widths = [];
            $lw = 0.0;
            $lastSpace = -1;
            foreach (mb_str_split($para) as $ch) {
                $cw = $this->charWidth($ch);
                if ($ch === ' ') {
                    $lastSpace = count($buf);
                }
                if ($lw + $cw > $wmax && $buf) {
                    if ($lastSpace >= 0 && $ch !== ' ') {
                        $out[] = implode('', array_slice($buf, 0, $lastSpace));
                        $buf = array_slice($buf, $lastSpace + 1);
                        $widths = array_slice($widths, $lastSpace + 1);
                        $lw = array_sum($widths);
                    } else {
                        $out[] = implode('', $buf);
                        $buf = [];
                        $widths = [];
                        $lw = 0.0;
                    }
                    $lastSpace = -1;
                }
                $buf[] = $ch;
                $widths[] = $cw;
                $lw += $cw;
            }
            $out[] = implode('', $buf);
        }
        return $out;
    }

    /** 折り返しながら書く（fpdf2 の multi_cell 相当）。書いたあとは、左余白(または元の位置)に戻り、行の下に進む */
    public function ml(float $w, float $h, string $txt, string $align = 'L'): void
    {
        $x = $this->GetX();
        if ($w == 0.0) {
            $w = $this->w - $this->rMargin - $x;
        }
        foreach ($this->wrapLines($w, $txt) as $line) {
            $this->SetX($x);
            $this->Cell($w, $h, $line, 0, 2, $align);
        }
        $this->SetX($x);
    }
}

/** 左余白に戻って、次の行へ */
function pdf_nl(ZPdf $pdf, float $w, float $h, string $txt, string $align = 'L', bool $fill = false): void
{
    $pdf->Cell($w, $h, $txt, 0, 1, $align, $fill);
}

// ── 仕入先への発注書（A4・3列） ──────────────────────────────────────────────

function build_order_pdf_file(int $orderId): string
{
    [$order, $blocks] = load_order($orderId);
    $sup = $order['supplier'] ?: 'ワールド美容';
    $contact = $order['contact_name'] ?? '';
    $date = "{$order['order_month']}月{$order['order_day']}日";

    $pdf = new ZPdf('A4');
    $pdf->titleText = "発注書　{$sup}御中　{$date}　{$order['store']}";
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $pdf->SetFont(PDF_FONT, '', 18);
    pdf_nl($pdf, 0, 10, "{$sup} 御中" . ($contact ? "　担当 {$contact}" : ''));
    $pdf->SetFont(PDF_FONT, '', 13);
    $pdf->Cell(110, 8, "発注日 {$date}　店舗 {$order['store']}");
    pdf_nl($pdf, 0, 8, '(有)イノ　TEL 072-999-6571', 'R');
    $pdf->Ln(2);

    $gap = 3;
    $colW = ($pdf->pageW() - $pdf->leftM() - $pdf->rightM() - $gap * (PDF_COLS - 1)) / PDF_COLS;
    $qtyW = 13;
    $nameW = $colW - $qtyW;
    $FS = 13;
    $rowH = 7.5;
    $lineH = 6;
    $headH = 7.5;
    $bottom = $pdf->pageH() - 16;
    $pos = ['col' => 0, 'top' => $pdf->GetY(), 'y' => $pdf->GetY()];

    $x0 = static function () use (&$pos, $pdf, $colW, $gap): float {
        return $pdf->leftM() + $pos['col'] * ($colW + $gap);
    };
    $colHead = static function () use (&$pos, $pdf, $x0, $nameW, $qtyW, $headH, $FS): void {
        $pdf->SetFont(PDF_FONT, '', $FS);
        $pdf->SetFillColor(235, 235, 235);
        $pdf->SetXY($x0(), $pos['y']);
        $pdf->Cell($nameW, $headH, ' 商品名', 1, 0, 'L', true);
        $pdf->Cell($qtyW, $headH, ' 数量', 1, 0, 'L', true);
        $pos['y'] += $headH;
    };
    $catRow = static function (string $text) use (&$pos, $pdf, $x0, $colW, $headH, $FS): void {
        $pdf->SetFont(PDF_FONT, '', $FS);
        $pdf->SetFillColor(205, 205, 205);
        $pdf->SetXY($x0(), $pos['y']);
        $pdf->Cell($colW, $headH, ' ' . $text, 1, 0, 'L', true);
        $pos['y'] += $headH;
    };
    $nextCol = static function () use (&$pos, $pdf, $colHead): void {
        $pos['col']++;
        if ($pos['col'] >= PDF_COLS) {
            $pdf->AddPage();
            $pos['col'] = 0;
            $pos['top'] = $pdf->GetY();
        }
        $pos['y'] = $pos['top'];
        $colHead();
    };
    $ensure = static function (float $h, ?string $contCat = null) use (&$pos, $bottom, $nextCol, $catRow): void {
        if ($pos['y'] + $h > $bottom) {
            $nextCol();
            if ($contCat !== null) {
                $catRow($contCat . '（続き）');
            }
        }
    };

    $colHead();
    foreach ($blocks as $cat => $items) {
        $cat = (string)$cat;
        $ensure($headH + $rowH);
        $catRow($cat);
        foreach ($items as $it) {
            $name = $it['name'] . ($it['note'] ? "（{$it['note']}）" : '');
            $pdf->SetFont(PDF_FONT, '', $FS);
            $n = count($pdf->wrapLines($nameW - 2, $name));
            $h = max($rowH, $n * $lineH + 1.5);
            $ensure($h, $cat);
            $pdf->SetFont(PDF_FONT, '', $FS);
            $x = $x0();
            $y = $pos['y'];
            $pdf->Rect($x, $y, $nameW, $h);
            $pdf->Rect($x + $nameW, $y, $qtyW, $h);
            $pdf->SetXY($x + 1, $y + ($h - $n * $lineH) / 2);
            $pdf->ml($nameW - 2, $lineH, $name);
            $pdf->SetXY($x + $nameW + 1, $y + ($h - $n * $lineH) / 2);
            $pdf->Cell($qtyW - 1, $lineH, num_fmt($it['qty']));
            $pos['y'] += $h;
        }
    }
    if (!$blocks) {
        $pdf->SetFont(PDF_FONT, '', 13);
        $pdf->SetXY($pdf->leftM(), $pos['y'] + 4);
        $pdf->Cell(0, 10, '発注品はありません');
    }
    return $pdf->Output('S');
}

// ── お客様の注文用紙（A5） ───────────────────────────────────────────────────

/** k は文字と行の大きさの倍率（1.0 が標準） */
function build_customer_order_pdf(array $order, array $items, int $subtotal, int $disc, int $total, string $discLabel, float $k): ZPdf
{
    $pdf = new ZPdf('A5');
    $pdf->titleText = 'ご注文用紙　' . sama_fmt($order['customer']);
    $M = 6;   // 余白（用紙をできるだけ使う）
    $pdf->SetMargins($M, $M, $M);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $W = $pdf->pageW() - 2 * $M;
    $qtyW = max(14.0, 8 * $k);
    $amtW = max(32.0, 20 * $k);
    $nameW = $W - $qtyW - $amtW;
    $bottom = $pdf->pageH() - 12;
    $sumW = max(62.0, 36 * $k);
    $f = PDF_FONT;

    $pdf->SetFont($f, '', 11 * $k);
    pdf_nl($pdf, 0, 6 * $k, 'ご注文用紙　受付 ' . jst_fmt($order['created_at']));
    $pdf->SetFont($f, '', 22 * $k);
    $pdf->ml(0, 11 * $k, sama_fmt($order['customer']));
    $pdf->SetX($pdf->leftM());
    $pdf->SetFont($f, '', 11 * $k);
    if ($order['phone']) {
        pdf_nl($pdf, 0, 6 * $k, "TEL {$order['phone']}");
    }
    pdf_nl($pdf, 0, 6 * $k, (string)$order['store']);
    $pdf->Ln(2 * $k);

    $head = static function () use ($pdf, $f, $k, $nameW, $qtyW, $amtW): void {
        $pdf->SetFont($f, '', 10 * $k);
        $pdf->SetFillColor(225, 225, 225);
        $pdf->Cell($nameW, 7 * $k, ' 商品名', 0, 0, 'L', true);
        $pdf->Cell($qtyW, 7 * $k, '数量', 0, 0, 'C', true);
        $pdf->Cell($amtW, 7 * $k, '金額 ', 0, 1, 'R', true);
    };
    $head();
    $lastIdx = count($items) - 1;
    foreach ($items as $idx => $it) {
        $pdf->SetFont($f, '', 11 * $k);
        $lines = $pdf->wrapLines($nameW - 2, (string)$it['name']);
        $sub = ($it['spec'] ? "{$it['spec']}　" : '') . yen_fmt($it['unit_price']) . ($it['label'] ? "　{$it['label']}" : '');
        $pdf->SetFont($f, '', 8.5 * $k);
        $subLines = $pdf->wrapLines($nameW - 2, $sub);
        $h = count($lines) * 5.6 * $k + count($subLines) * 4.5 * $k + 3;
        if ($pdf->GetY() + $h > $bottom - ($idx === $lastIdx ? 24 * $k : 0)) {
            $pdf->AddPage();
            $head();
        }
        $y = $pdf->GetY();
        $pdf->SetXY($pdf->leftM() + 1, $y + 1);
        $pdf->ml($nameW - 2, 5.6 * $k, (string)$it['name']);
        $pdf->SetFont($f, '', 8.5 * $k);
        $pdf->SetX($pdf->leftM() + 1);
        $pdf->ml($nameW - 2, 4.5 * $k, $sub);
        $pdf->SetFont($f, '', 13 * $k);
        $pdf->SetXY($pdf->leftM() + $nameW, $y);
        $pdf->Cell($qtyW, $h, (string)$it['qty'], 0, 0, 'C');
        $pdf->Cell($amtW - 1, $h, yen_fmt($it['amount']), 0, 0, 'R');
        // 区切り線だけ灰色にして、すぐ黒に戻す（文字の輪郭も同じ「線の色」を使うため、戻さないと文字が薄くなる）
        $pdf->SetDrawColor(190, 190, 190);
        $pdf->Line($pdf->leftM(), $y + $h, $pdf->pageW() - $pdf->rightM(), $y + $h);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetXY($pdf->leftM(), $y + $h);
    }
    $pdf->Ln(2 * $k);
    if ($disc) {
        $pdf->SetFont($f, '', 12 * $k);
        $pdf->Cell($W - $sumW, 8 * $k, '小計 ' . count($items) . ' 品');
        pdf_nl($pdf, $sumW, 8 * $k, yen_fmt($subtotal), 'R');
        $pdf->Cell($W - $sumW, 8 * $k, $discLabel);
        pdf_nl($pdf, $sumW, 8 * $k, '－' . yen_fmt($disc), 'R');
    }
    $pdf->SetFont($f, '', 18 * $k);
    pdf_nl($pdf, 0, 12 * $k, '合計 ' . yen_fmt($total), 'R');
    $pdf->SetFont($f, '', 9 * $k);
    pdf_nl($pdf, 0, 5 * $k, ($disc ? '' : count($items) . ' 品　') . rtrim(PRICE_NOTE, '。'), 'R');
    if ($order['memo']) {
        $pdf->Ln(2 * $k);
        $pdf->SetFont($f, '', 10 * $k);
        $pdf->ml(0, 5.5 * $k, "メモ: {$order['memo']}");
    }
    return $pdf;
}

// ── 未発注の商品の一覧（A4） ─────────────────────────────────────────────────

function build_unordered_pdf(array $groups, string $title): string
{
    $pdf = new ZPdf('A4');
    $pdf->titleText = $title;
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 16);
    $pdf->AddPage();
    $f = PDF_FONT;
    $pdf->SetFont($f, '', 18);
    pdf_nl($pdf, 0, 10, $title);
    $pdf->SetFont($f, '', 10);
    pdf_nl($pdf, 0, 6, jst_fmt(now_jst()) . ' 作成　' . count($groups) . ' 品目');
    $pdf->Ln(2);
    if (!$groups) {
        $pdf->SetFont($f, '', 13);
        pdf_nl($pdf, 0, 10, '未発注の商品はありません。');
    }
    $W = $pdf->pageW() - 24;
    foreach ($groups as $g) {
        $need = 8 + 6 * count($g['who']) + 2;
        if ($pdf->GetY() + $need > $pdf->pageH() - 16) {
            $pdf->AddPage();
        }
        $y = $pdf->GetY();
        $pdf->Rect($pdf->leftM(), $y + 1.5, 4.5, 4.5);   // 発注したらチェックを入れる四角
        $pdf->SetXY($pdf->leftM() + 7, $y);
        $pdf->SetFont($f, '', 12);
        $pdf->Cell($W - 7 - 24, 8, $g['name'] . ($g['spec'] ? "　{$g['spec']}" : ''));
        $pdf->SetFont($f, '', 14);
        pdf_nl($pdf, 24, 8, '× ' . $g['qty'], 'R');
        $pdf->SetFont($f, '', 10);
        foreach ($g['who'] as [$who, $q]) {
            $pdf->SetX($pdf->leftM() + 7);
            $pdf->Cell($W - 7 - 24, 6, $who);
            pdf_nl($pdf, 24, 6, (string)$q, 'R');
        }
        $pdf->SetDrawColor(190, 190, 190);
        $pdf->Line($pdf->leftM(), $pdf->GetY() + 1, $pdf->pageW() - $pdf->rightM(), $pdf->GetY() + 1);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Ln(3);
    }
    return $pdf->Output('S');
}

// ── お客様向けの価格表（A4） ─────────────────────────────────────────────────

function build_prices_pdf(array $groups, DateTimeImmutable $now): string
{
    $pdf = new ZPdf('A4');
    $pdf->titleText = PRICE_TITLE;
    $pdf->SetMargins(15, 12, 15);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $f = PDF_FONT;

    $pdf->SetFont($f, '', 24);
    pdf_nl($pdf, 0, 13, PRICE_TITLE);
    $pdf->SetFont($f, '', 11);
    pdf_nl($pdf, 0, 6, $now->format('Y') . '年' . $now->format('n') . '月' . $now->format('j') . '日現在');
    pdf_nl($pdf, 0, 6, PRICE_NOTE);
    $pdf->Ln(3);

    $nameW = 100;
    $specW = 42;
    $priceW = 38;
    $lineH = 6.5;
    $FS = 12;
    $bottom = $pdf->pageH() - 16;

    $catBar = static function (string $text) use ($pdf, $f, $nameW, $specW, $priceW): void {
        $pdf->SetFont($f, '', 12);
        $pdf->SetFillColor(215, 215, 215);
        $pdf->SetX($pdf->leftM());
        $pdf->Cell($nameW + $specW + $priceW, 8, ' ' . $text, 0, 1, 'L', true);
    };
    if (!$groups) {
        $pdf->SetFont($f, '', 13);
        pdf_nl($pdf, 0, 10, 'まだ商品が登録されていません');
    }
    foreach ($groups as $cat => $items) {
        $cat = (string)$cat;
        if ($pdf->GetY() + 8 + 10 > $bottom) {
            $pdf->AddPage();
        }
        $catBar($cat);
        foreach ($items as $it) {
            $name = $it['name'] . ($it['note'] ? "（{$it['note']}）" : '');
            $spec = (string)($it['spec'] ?? '');
            $pdf->SetFont($f, '', $FS);
            $nName = count($pdf->wrapLines($nameW - 2, $name));
            $nSpec = $spec !== '' ? count($pdf->wrapLines($specW - 2, $spec)) : 1;
            $h = max($nName, $nSpec) * $lineH + 3;
            if ($pdf->GetY() + $h > $bottom) {
                $pdf->AddPage();
                $catBar($cat . '（続き）');
            }
            $y = $pdf->GetY();
            $pdf->SetFont($f, '', $FS);
            $pdf->SetXY($pdf->leftM() + 1, $y + 1.5);
            $pdf->ml($nameW - 2, $lineH, $name);
            if ($spec !== '') {
                $pdf->SetXY($pdf->leftM() + $nameW + 1, $y + 1.5);
                $pdf->ml($specW - 2, $lineH, $spec);
            }
            $pdf->SetFont($f, '', 14);
            $pdf->SetXY($pdf->leftM() + $nameW + $specW, $y);
            $pdf->Cell($priceW - 2, $h, yen_fmt($it['price']), 0, 0, 'R');
            $pdf->SetDrawColor(190, 190, 190);   // 区切り線だけ灰色。文字の輪郭に使う線の色は、すぐ黒に戻す
            $pdf->Line($pdf->leftM(), $y + $h, $pdf->pageW() - $pdf->rightM(), $y + $h);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetXY($pdf->leftM(), $y + $h);
        }
        $pdf->Ln(3);
    }
    return $pdf->Output('S');
}
