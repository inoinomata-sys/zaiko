"""レジ（POS）の店販商品CSVを、お客様向けの価格表（price_items）に取り込む。
使い方:  DATABASE_URL=... python3 tools/import_pos_goods.py goods_YYYYMMDDhhmmss.csv
同じ店販IDの商品は、名前・値段・並びを更新する（再取り込みしても重複しない）。CSVに無くなった商品は非表示にする。
"""
import csv, io, os, re, sys, unicodedata
import psycopg2

# 店販中分類ID → 価格表のカテゴリ名と並び順（CSVに分類名が無いため、商品の中身から付けた名前）
CATEGORIES = {1: ('シャンプー', 1), 2: ('トリートメント', 2), 3: ('育毛・頭皮ケア', 3), 12: ('整髪料', 4), 4: ('化粧品', 5),
              5: ('コタ', 6), 10: ('コアミー シャンプー', 7), 11: ('コアミー ケア用品', 8), 13: ('リファ', 9),
              14: ('アルマダ', 10), 7: ('アイロン・バリカン', 11), 6: ('その他', 12)}
HALF_KANA = re.compile('[｡-ﾟ]')


def clean(name):
    """半角カナ・全角英数を整える。半角カナの名前では、カナの後ろの「-」は伸ばす棒「ー」にする"""
    half = bool(HALF_KANA.search(name))
    s = unicodedata.normalize('NFKC', name).strip()
    if half:
        s = re.sub(r'(?<=[ァ-ヶー])-', 'ー', s)
    return re.sub(r'\s+', ' ', s)


def main(path):
    raw = open(path, 'rb').read().decode('cp932')
    rows = list(csv.DictReader(io.StringIO(raw)))
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
        items.append((int(r['店販ID']), cat[0], clean(r['店販名']), price, cat[1] * 100000 + int(r['表示順'] or 0)))
    db = psycopg2.connect(os.environ['DATABASE_URL'])
    db.autocommit = False
    cur = db.cursor()
    cur.execute("ALTER TABLE price_items ADD COLUMN IF NOT EXISTS pos_id INTEGER")
    cur.execute("CREATE UNIQUE INDEX IF NOT EXISTS price_items_pos_id ON price_items (pos_id) WHERE pos_id IS NOT NULL")
    for pos_id, cat, name, price, order in items:
        cur.execute("""INSERT INTO price_items (pos_id, category, name, price, sort_order, is_active) VALUES (%s,%s,%s,%s,%s,true)
                       ON CONFLICT (pos_id) WHERE pos_id IS NOT NULL
                       DO UPDATE SET category=EXCLUDED.category, name=EXCLUDED.name, price=EXCLUDED.price,
                                     sort_order=EXCLUDED.sort_order, is_active=true""", (pos_id, cat, name, price, order))
    cur.execute("UPDATE price_items SET is_active=false WHERE pos_id IS NOT NULL AND NOT (pos_id = ANY(%s))", ([i[0] for i in items],))
    db.commit()
    cur.execute("SELECT category, COUNT(*) FROM price_items WHERE is_active GROUP BY 1 ORDER BY MIN(sort_order)")
    print('取り込み:', len(items), '品 / 対象外:', skipped)
    for c, n in cur.fetchall():
        print(f'  {c}: {n}品')
    db.close()


if __name__ == '__main__':
    main(sys.argv[1])
