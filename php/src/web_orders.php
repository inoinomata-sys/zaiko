<?php
declare(strict_types=1);

function h_web_orders(): void
{
    render('web_orders', ['sites' => [
        ['name' => 'アリミノコアミー', 'url' => 'https://market.arimino.co.jp/shop/salontop.aspx', 'host' => 'market.arimino.co.jp'],
        ['name' => 'ナプラ', 'url' => 'https://www.naplatag.jp/Form/Salon/SalonLogin.aspx', 'host' => 'www.naplatag.jp'],
        ['name' => 'オーガニックノート', 'url' => 'https://www.dresspoint.co.jp/NoteOrder/', 'host' => 'www.dresspoint.co.jp'],
        ['name' => 'リファ', 'url' => 'https://b2b-online.mtg.gr.jp', 'host' => 'b2b-online.mtg.gr.jp'],
        ['name' => 'リーディアル', 'url' => 'https://ledeal-ec.com/aec/user/login', 'host' => 'ledeal-ec.com'],
    ]]);
}
