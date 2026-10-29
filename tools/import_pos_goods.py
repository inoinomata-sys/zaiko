"""レジ（POS）の店販商品CSVを、お客様向けの価格表（price_items）に取り込む。
使い方:  DATABASE_URL=... python3 tools/import_pos_goods.py [--dry-run] goods_YYYYMMDDhhmmss.csv [...]
CSVは複数まとめて渡せる（中分類ごとに書き出したものを全部）。
同じ店販IDの商品は、名前・値段・並びを更新する（再取り込みしても重複しない）。CSVに無くなった商品は削除する（過去のお客様注文には名前・値段が残る）。
"""
import csv, io, os, re, sys, unicodedata
import psycopg2

# 店販中分類ID → 価格表のカテゴリ名と並び順（レジの「店販中分類マスター」の名前・表示順。CSVの中分類IDは、マスターのコードとは別番号）
CATEGORIES = {10: ('アリミノ シャンプー', 1), 11: ('アリミノ トリートメント', 2), 27: ('アリミノ 1ヶ月ケア・アウトバス・ローション', 3),
              5: ('コタ', 4), 1: ('シャンプーその他', 5), 2: ('トリートメントその他', 6), 12: ('スタイリング剤・スタイリングオイル', 7),
              3: ('育毛剤・頭皮ケア', 8), 4: ('メイクアイテム・化粧品', 9), 24: ('イムダインシリーズ', 10), 13: ('リファシリーズ', 11),
              14: ('アルマダシリーズ', 12), 25: ('ステラボーテシリーズ', 13), 26: ('アモールシリーズ', 14), 6: ('その他商品', 15)}
HALF_KANA = re.compile('[｡-ﾟ]')


def clean(name):
    """半角カナ・全角英数を整える。半角カナの名前では、カナの後ろの「-」は伸ばす棒「ー」にする"""
    half = bool(HALF_KANA.search(name))
    s = unicodedata.normalize('NFKC', name).strip()
    if half:
        s = re.sub(r'(?<=[ァ-ヶー])-', 'ー', s)
    return re.sub(r'\s+', ' ', s)


def read_rows(path):
    data = open(path, 'rb').read()
    try:
        raw = data.decode('utf-8-sig')
    except UnicodeDecodeError:
        raw = data.decode('cp932')
    return list(csv.DictReader(io.StringIO(raw)))


def main(paths, dry=False):
    rows = [r for p in paths for r in read_rows(p)]
    items, skipped = [], {'price0': 0, 'unknown_category': 0}
    for r in rows:
        price = int(float(r['販売単価'] or 0))
        if price <= 0:
            skipped['price0'] += 1
            continue
        cat = CATEGORIES.get(int(r['店販中分類ID']))
        if not cat:
            skipped['unknown_category'] += 1
            continue
        items.append((int(r['店販ID']), cat[0], clean(r['店販名']), price, cat[1] * 100000 + min(int(r['表示順'] or 0), 99999)))
    db = psycopg2.connect(os.environ['DATABASE_URL'])
    db.autocommit = False
    cur = db.cursor()
    if dry:
        cur.execute("SELECT pos_id, category, name, price, is_active FROM price_items WHERE pos_id IS NOT NULL")
        old = {r[0]: r for r in cur.fetchall()}
        new_ids = {i[0] for i in items}
        print('追加:', [i[2] for i in items if i[0] not in old])
        print('名前が変わる:', [(old[i[0]][2], i[2]) for i in items if i[0] in old and old[i[0]][2] != i[2]])
        print('値段が変わる:', [(i[2], old[i[0]][3], i[3]) for i in items if i[0] in old and old[i[0]][3] != i[3]])
        print('削除される:', [(o[1], o[2]) for k, o in old.items() if k not in new_ids and o[4]])
        print('取り込み:', len(items), '品 / 対象外:', skipped)
        db.close()
        return
    cur.execute("ALTER TABLE price_items ADD COLUMN IF NOT EXISTS pos_id INTEGER")
    cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS price_items_pos_id ON price_items (pos_id) WHERE pos_id IS NOT NULL")
    for pos_id, cat, name, price, order in items:
        cur.execute("""INSERT INTO price_items (pos_id, category, name, price, sort_order, is_active) VALUES (%s,%s,%s,%s,%s,true)
                       ON CONFLICT (pos_id) WHERE pos_id IS NOT NULL
                       DO UPDATE SET category=EXCLUDED.category, name=EXCLUDED.name, price=EXCLUDED.price,
                                     sort_order=EXCLUDED.sort_order, is_active=true""", (pos_id, cat, name, price, order))
    cur.execute("DELETE FROM price_items WHERE pos_id IS NOT NULL AND NOT (pos_id = ANY(%s))", ([i[0] for i in items],))
    db.commit()
    cur.execute("SELECT category, COUNT(*) FROM price_items WHERE is_active GROUP BY 1 ORDER BY MIN(sort_order)")
    print('取り込み:', len(items), '品 / 対象外:', skipped)
    for c, n in cur.fetchall():
        print(f'  {c}: {n}品')
    db.close()


if __name__ == '__main__':
    args = [a for a in sys.argv[1:] if a != '--dry-run']
    main(args, '--dry-run' in sys.argv)
