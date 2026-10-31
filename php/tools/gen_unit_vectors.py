"""PHP 版の計算が、Flask 版（Python）と同じ答えになるかを確かめるための「正解」を書き出す。
php/tests/vectors.json を作る。php/tests/unit.php が、これと PHP の結果を照らし合わせる（GitHub Actions で自動実行）。
使い方:  python3 php/tools/gen_unit_vectors.py
"""
import json
import os
import random
import sys
from unittest import mock

ROOT = os.path.join(os.path.dirname(__file__), '..', '..')
import ast
import unicodedata

# app.py は読み込み時にデータベースへつなぐので、読み込まずに、計算に使う関数と定数だけを取り出す
_src = open(os.path.join(ROOT, 'app.py'), encoding='utf-8').read()
_tree = ast.parse(_src)
_need_funcs = {'calc_discount_lines', 'campaign_tier', 'price_kind', 'kind_rate', 'pct_fmt', 'sama_fmt', 'yen_fmt', 'num_fmt', 'nfkc'}
_need_consts = {'DISCOUNT_ROUND', 'CAMPAIGN_TIERS', 'CAMPAIGN_TOP', 'ORGANIC_NOTE_PREFIXES', 'ORGANIC_NOTE_NAMES', 'NO_DISCOUNT_NAMES'}
_keep = []
for node in _tree.body:
    if isinstance(node, ast.FunctionDef) and node.name in _need_funcs:
        node.decorator_list = []
        _keep.append(node)
    elif isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id in _need_consts for t in node.targets):
        _keep.append(node)
_ns = {'unicodedata': unicodedata}
exec(compile(ast.Module(body=_keep, type_ignores=[]), 'app_pure', 'exec'), _ns)


class _App:
    pass


app = _App()
for _n in _need_funcs:
    setattr(app, _n, _ns[_n])

random.seed(7240001)
v = {}

# 割引の計算: 行ごと [金額, 割引率]
cases = []
for _ in range(400):
    n = random.randint(1, 6)
    pairs = []
    for _ in range(n):
        amount = random.choice([550, 880, 1100, 1650, 2200, 2650, 2970, 4400, 6600, 8100, 8800, 11000, 13200, 22000, 30600]) * random.randint(1, 9)
        pct = random.choice([None, 0, 5, 8, 10, 12, 13, 14, 16, 20.2, 14.5, 16.4, 17.35, 12.6, 15.17, 3.33])
        pairs.append([amount, pct])
    d, p = app.calc_discount_lines([(a, pc) for a, pc in pairs])
    cases.append({'pairs': pairs, 'disc': d, 'pay': p})
v['discount'] = cases

v['kind_rate'] = [{'kind': k, 'tier': t, 'rate': app.kind_rate(k, t)} for k in ('n', 'a', 'f5', 'f10', 'z') for t in (0, 8, 10, 12, 13, 14, 16)]
v['campaign_tier'] = [{'total': t, 'tier': app.campaign_tier(t)} for t in (-5, 0, 1, 14999, 15000, 15001, 20000, 20001, 29999, 30000, 40000, 40001, 79999, 80000, 80001, 163490)]
names = [('アリミノ シャンプー', '新コアミーシャンプーS 250ml'), ('アルマダシリーズ', 'アルマダ1000ml'), ('アルマダシリーズ', 'アルマダ 1000ml'),
         ('アルマダシリーズ', 'アルマダ200ml'), ('アルマダシリーズ', 'エグータムプレミアム'), ('アルマダシリーズ', 'ピーダ'),
         ('リファシリーズ', 'MTGパワーガン'), ('リファシリーズ', 'リファドライヤー'), ('その他商品', 'リファカールアイロン'), ('ステラボーテシリーズ', 'ステラボーテブラシ'),
         ('シャンプーその他', 'N.オーガニックノート シャンプー'), ('シャンプーその他', 'オーガニックノートバーム'), ('コタ', 'コタ3キロトリートメント'), (None, None), ('', ''),
         ('シャンプーその他', 'モイストシトラス250ml'), ('シャンプーその他', 'スムースサクラ250ml'), ('トリートメントその他', 'CMCパックハーバル1リットル'),
         ('トリートメントその他', 'CMCマスク180g'), ('トリートメントその他', 'ホワイトオーキット詰替'), ('シャンプーその他', 'トライアルセット'),
         ('シャンプーその他', '詰替えホルダー'), ('アリミノ 1ヶ月ケア・アウトバス・ローション', 'コアミー1ヶ月S'), ('アリミノ 1ヶ月ケア・アウトバス・ローション', 'コアミー1ヶ月ER'), ('スタイリング剤・スタイリングオイル', 'トラックオイル no.3'), ('スタイリング剤・スタイリングオイル', 'トラックオイル フタ'), ('シャンプーその他', 'ホーユーシャンプー'), ('シャンプーその他', '新ミントマイルド250ml'),
         ('トリートメントその他', 'ラメラメトリートメント 1Kg'), ('トリートメントその他', 'ケアテクトしっとりtr1200g')]
v['price_kind'] = [{'category': c, 'name': n, 'kind': app.price_kind(c, n)} for c, n in names]
v['num_fmt'] = [{'in': x, 'out': app.num_fmt(x)} for x in (None, 0, 1, 3.0, 2.5, 0.1, 0.25, 12.75, 100, 1234567.0, 0.3333333, 10.5, 7.0)]
v['pct_fmt'] = [{'in': x, 'out': app.pct_fmt(x)} for x in (None, 0, 16, 16.0, 20.2, 14.5, 17.35, 12.6, '16.00', '20.20', 3.33)]
v['sama'] = [{'in': x, 'out': app.sama_fmt(x)} for x in ('山田', '山田 様', '  田中花子  ', '佐藤様', '', None)]
v['yen'] = [{'in': x, 'out': app.yen_fmt(x)} for x in (0, 1, 999, 1000, 163490, 1234567, 4400.0)]
v['nfkc'] = [{'in': x, 'out': app.nfkc(x)} for x in ('ＡＢＣ１２３', 'ｺﾀｼｬﾝﾌﾟｰ', ' 新ｺｱﾐｰ S ', 'ホーユー', 'ﾎｰﾕｰ', 'ｱﾙﾏﾀﾞ1000ml', '　全角空白　', '')]

out = os.path.join(ROOT, 'php', 'tests', 'vectors.json')
with open(out, 'w', encoding='utf-8') as f:
    json.dump(v, f, ensure_ascii=False, indent=1)
print('書き出し:', out, {k: len(x) for k, x in v.items()})
