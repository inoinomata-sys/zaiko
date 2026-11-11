<?php
declare(strict_types=1);

require_once __DIR__ . '/pdf.php';

/** 印刷用の月間カレンダー。予定が多い週は日付を再掲して次の用紙に続ける。 */
function build_schedule_pdf(array $calendar): string
{
    $pdf = new ZPdf('A5');
    $pdf->SetMargins(6, 6, 6);
    $pdf->SetAutoPageBreak(false);
    $pdf->titleText = $calendar['nav']['label'] . '　店舗スケジュール';
    $pdf->SetTitle($pdf->titleText, true);
    $width = ($pdf->pageW() - 12) / 7;
    $lineHeight = 3.1;
    $bottom = $pdf->pageH() - 15;

    $newPage = static function () use ($pdf, $width): float {
        $pdf->AddPage();
        $pdf->SetDrawColor(45);
        $pdf->SetLineWidth(.07);
        if ($pdf->PageNo() === 1) {
            $pdf->SetFont(PDF_FONT, '', 14);
            $pdf->Cell(0, 10, $pdf->titleText, 0, 1);
        }
        $pdf->SetFont(PDF_FONT, '', 8);
        foreach (['月','火','水','木','金','土','日'] as $label) {
            $pdf->Cell($width, 6, $label, 0, 0, 'C');
        }
        return $pdf->GetY() + 6;
    };

    $y = $newPage();
    foreach ($calendar['weeks'] as $week) {
        $pdf->SetFont(PDF_FONT, '', 7);
        $columns = [];
        foreach ($week as $day) {
            $lines = [];
            if ($day) {
                $labels = array_filter([$day['hol'], $day['closed'] ? '店休日' : '']);
                foreach ($day['events'] as $event) {
                    $labels[] = $event['title'] . ($event['store'] === '' ? '' : '（' . $event['store'] . '）');
                }
                foreach ($labels as $label) {
                    array_push($lines, ...$pdf->wrapLines($width, $label));
                }
            }
            $columns[] = $lines;
        }
        $lineCount = max(array_map('count', $columns));
        $offset = 0;
        do {
            $needed = max(19, 6 + ($lineCount - $offset) * $lineHeight);
            if ($y > 23 && $needed > $bottom - $y) {
                $y = $newPage();
            }
            $count = min($lineCount - $offset, (int)floor(($bottom - $y - 6) / $lineHeight));
            $height = max(19, 6 + $count * $lineHeight);
            foreach ($week as $col => $day) {
                $x = 6 + $col * $width;
                $pdf->SetDrawColor(145);
                $pdf->Rect($x, $y, $width, $height);
                if (!$day) { continue; }
                $pdf->SetDrawColor(45);
                $pdf->SetFont(PDF_FONT, '', 9);
                $pdf->SetXY($x, $y);
                $pdf->Cell($width, 5, (string)$day['d']);
                $pdf->SetFont(PDF_FONT, '', 7);
                foreach (array_slice($columns[$col], $offset, $count) as $i => $line) {
                    $pdf->SetXY($x, $y + 5 + $i * $lineHeight);
                    $pdf->Cell($width, $lineHeight, $line);
                }
            }
            $y += $height;
            $offset += $count;
        } while ($offset < $lineCount);
    }
    return $pdf->Output('S');
}
