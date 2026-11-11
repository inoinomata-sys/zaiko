<?php
declare(strict_types=1);
require_once __DIR__ . '/pdf.php';
class ShiftHolidayPdf extends ZPdf
{
    public function Header(): void { $this->_out('0 Tr'); }
    public function Footer(): void {}
    public function textWeight(array $color): void { $this->SetDrawColor(...$color); $this->SetLineWidth(.06); $this->_out('2 Tr'); }
    public function circle(float $x, float $y, float $r): void
    {
        $k=$this->k; $h=$this->h; $c=.55228475*$r;
        $this->_out(sprintf('%.3F %.3F m',($x+$r)*$k,($h-$y)*$k));
        foreach ([[$x+$r,$y+$c,$x+$c,$y+$r,$x,$y+$r],[$x-$c,$y+$r,$x-$r,$y+$c,$x-$r,$y],[$x-$r,$y-$c,$x-$c,$y-$r,$x,$y-$r],[$x+$c,$y-$r,$x+$r,$y-$c,$x+$r,$y]] as $p) {
            $this->_out(sprintf('%.3F %.3F %.3F %.3F %.3F %.3F c',$p[0]*$k,($h-$p[1])*$k,$p[2]*$k,($h-$p[3])*$k,$p[4]*$k,($h-$p[5])*$k));
        }
        $this->_out('S');
    }
}
function build_shift_customer_pdf(array $data): string
{
    $pdf=new ShiftHolidayPdf('A5'); $pdf->SetMargins(6,6,6); $pdf->SetAutoPageBreak(false); $pdf->AddPage('L');
    $rgb=static fn($hex)=>array_map('hexdec',str_split(ltrim($hex,'#'),2));
    $accent=$rgb($data['theme']['color']);$soft=$rgb($data['theme']['soft']);
    $text=static function(float $x,float $y,string $value,float $size=8,array $color=[48,51,51],float $width=0) use($pdf): void {
        $pdf->SetTextColor(...$color);$pdf->textWeight($color);$pdf->SetFont(PDF_FONT,'',$size);
        if($width>0){while($pdf->GetStringWidth($value)>$width&&$size>5.2){$size-=.2;$pdf->SetFont(PDF_FONT,'',$size);}}
        $pdf->SetXY($x,$y);$pdf->Cell($width,4,$value);
    };
    $text(7,8,sprintf('%02d',$data['month_number']),32,$accent);$text(26,9,$data['theme']['en'],13,$accent);$text(26,16,(string)$data['year'],8,[100,100,100]);
    $text(65,8,'スタイリストのお休み',15);$text(65,16,'イノ店',8);$text(65,22,'INO Inc. / Store Management',7,[100,100,100]);
    $pdf->SetDrawColor(...$accent);$pdf->SetLineWidth(.25);$pdf->Line(6,29,204,29);
    // 季節の線画。すべてPDF内のベクターなので印刷しても粗くならない。
    $m=$data['month_number'];
    if(in_array($m,[1,2,3,4,10],true)){
        $pdf->Line(170,27,197,7);$pdf->Line(179,20,175,9);
        foreach([[176,10],[184,16],[196,8]] as [$x,$y]){
            $petals=$m===2?8:($m===10?4:5);
            for($i=0;$i<$petals;$i++){$a=$i*2*M_PI/$petals;$pdf->circle($x+sin($a)*1.8,$y+cos($a)*1.8,$m===3?.8:1.3);}
            $pdf->circle($x,$y,.5);
        }
    }elseif(in_array($m,[5,11,12],true)){
        $pdf->Line(171,27,196,5);
        foreach([[178,21],[184,16],[190,11]] as [$x,$y]){
            if($m===11){$pts=[[$x,$y],[$x-4,$y-2],[$x-2,$y-3],[$x-4,$y-6],[$x-1,$y-5],[$x,$y-9],[$x+1,$y-5],[$x+4,$y-6],[$x+2,$y-3],[$x+4,$y-2],[$x,$y]];for($i=1;$i<count($pts);$i++)$pdf->Line(...[...$pts[$i-1],...$pts[$i]]);}
            else{$pdf->Line($x,$y,$x-4,$y-7);$pdf->Line($x,$y,$x+6,$y-2);}
        }
    }elseif($m===6){foreach([[180,14],[185,11],[189,16],[184,19]] as [$x,$y])for($i=0;$i<4;$i++)$pdf->circle($x+sin($i*M_PI/2),$y+cos($i*M_PI/2),1);}
    elseif($m===7){foreach([[178,15],[188,21],[199,11]] as [$x,$y]){$pdf->Line($x,5,$x,$y-2);$pdf->Line($x-2,$y,$x+2,$y);$pdf->Line($x,$y-2,$x,$y+2);}}
    elseif($m===8){$pdf->circle(187,15,3);for($i=0;$i<12;$i++){$a=$i*M_PI/6;$pdf->circle(187+cos($a)*5,15+sin($a)*5,1.7);}}
    else{$pdf->circle(190,12,5);$pdf->Line(172,27,179,13);$pdf->Line(177,27,187,18);}
    $x0=6.;$cw=198/7;$y0=36.;$weeks=$data['weeks'];$rh=92/count($weeks);
    foreach(['月','火','水','木','金','土','日'] as $i=>$w)$text($x0+$i*$cw+11,31,$w,7,$i===6?[173,103,102]:($i===5?[104,131,151]:[110,110,110]));
    foreach($weeks as $r=>$week)foreach($week as $c=>$day){$x=$x0+$c*$cw;$y=$y0+$r*$rh;$pdf->SetDrawColor(225,221,214);$pdf->SetFillColor(...($day?[255,255,255]:[249,249,249]));$pdf->Rect($x,$y,$cw,$rh,'DF');if(!$day)continue;
        $text($x+1,$y+.5,(string)$day['d'],8,$day['sun']?[173,103,102]:($day['sat']?[104,131,151]:[48,51,51]));
        if($day['hol'])$text($x+6,$y+.7,$day['hol'],5.2,[150,100,95],$cw-7);
        $full=array_values(array_filter($day['holidays'],static fn($p)=>$p['full']));$partial=array_values(array_filter($day['holidays'],static fn($p)=>!$p['full']));
        foreach($full as $i=>$person)$text($x+1+($i%3)*($cw-2)/3,$y+4+intdiv($i,3)*3,$person['name'],6.5,[48,51,51],($cw-2)/3);
        $py=$y+4+ceil(count($full)/3)*3;
        foreach($partial as $person){$text($x+1,$py,$person['name'].' '.$person['note'],6.1,$accent,$cw-2);$py+=3.2;}
    }
    $pdf->SetDrawColor(...$accent);$pdf->Line(6,130,204,130);
    $text(6,132,'お名前の記載がある日は、そのスタイリストのお休みです。',6.4,[90,90,90]);
    $text(6,136,'時間の記載は、一部のお休み・勤務時間の変更です。',6.4,[90,90,90]);
    $text(146,132,'ご予約の際にご確認ください。',6.4,[90,90,90]);$text(146,136,'予定が変更になる場合がございます。',6.4,[90,90,90]);
    if(($data['staff_count']??1)===0)$text(45,74,'職種が未設定です。スタッフ設定でスタイリストを選んでください。',9,[140,70,60]);
    $pdf->SetTitle($data['nav']['label'].' イノ スタイリストのお休み',true);
    return $pdf->Output('S');
}
