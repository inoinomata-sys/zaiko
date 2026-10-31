"""Flask(Jinja2)の画面（templates/*.html）を、PHP用(Twig)に変換して php/templates/*.twig に書き出す。
Twig は Jinja2 とほぼ同じ書き方。違う所（`A if 条件 else B`、`.items()`、`.get()`、`startswith`）だけ直す。
元の templates/ は変えない（Render 版はそのまま動かし続ける）。
使い方:  python3 php/tools/convert_templates.py
"""
import glob
import os
import re

ROOT = os.path.join(os.path.dirname(__file__), '..', '..')
SRC = os.path.join(ROOT, 'templates')
DST = os.path.join(ROOT, 'php', 'templates')
SKIP = {'print_pdf.html'}   # どの画面からも使われていない古い雛形


def split_top(expr, word):
    """括弧・引用符の外にある ' word ' の位置（最初の1つ）を返す。なければ -1"""
    depth, quote, i = 0, None, 0
    while i < len(expr):
        c = expr[i]
        if quote:
            if c == '\\':
                i += 1
            elif c == quote:
                quote = None
        elif c in '\'"':
            quote = c
        elif c in '([{':
            depth += 1
        elif c in ')]}':
            depth -= 1
        elif depth == 0 and expr.startswith(word, i):
            return i
        i += 1
    return -1


def convert_groups(expr):
    """括弧の中身も、同じ規則で変換する"""
    out, depth, quote, i, start = [], 0, None, 0, None
    buf = ''
    while i < len(expr):
        c = expr[i]
        if quote:
            buf += c
            if c == '\\':
                i += 1
                buf += expr[i]
            elif c == quote:
                quote = None
        elif c in '\'"':
            quote = c
            buf += c
        elif c == '(' and depth == 0:
            depth, start, buf_before = 1, i, buf
            buf = ''
            j, d, q = i + 1, 1, None
            while j < len(expr) and d:
                ch = expr[j]
                if q:
                    if ch == '\\':
                        j += 1
                    elif ch == q:
                        q = None
                elif ch in '\'"':
                    q = ch
                elif ch == '(':
                    d += 1
                elif ch == ')':
                    d -= 1
                j += 1
            inner = expr[i + 1:j - 1]
            out.append(buf_before + '(' + convert_expr(inner) + ')')
            buf, depth, i = '', 0, j
            continue
        else:
            buf += c
        i += 1
    return ''.join(out) + buf


def convert_expr(expr):
    """`X if C else Y` → `(C) ? (X) : (Y)`（入れ子も）"""
    e = expr.strip()
    i = split_top(e, ' if ')
    if i >= 0:
        j = split_top(e[i + 4:], ' else ')
        if j >= 0:
            x, c, y = e[:i], e[i + 4:i + 4 + j], e[i + 4 + j + 6:]
            return f"(({convert_expr(c)}) ? ({convert_expr(x)}) : ({convert_expr(y)}))"
    return convert_groups(e)


def fix_common(body):
    body = re.sub(r"request\.path\.startswith\(('[^']*')\)", r"request.path starts with \1", body)
    body = re.sub(r"(\w+)\.get\(([^,()]+),\s*([^()]+)\)", r"(\1[\2] ?? \3)", body)
    body = re.sub(r"request\.args\.get\(('[^']*')\)", lambda m: 'request.args.' + m.group(1).strip("'"), body)
    body = body.replace("|replace(' ', '')", "|replace({' ': ''})")
    body = re.sub(r"\bNone\b", "null", body)
    body = re.sub(r"\bTrue\b", "true", body)
    body = re.sub(r"\bFalse\b", "false", body)
    return body


def convert_text(s):
    def sub_print(m):
        b = fix_common(m.group(1))
        m2 = re.fullmatch(r"\s*([\w.]+) or ('[^']*')\s*", b)
        if m2:
            return '{{ ' + f"{m2.group(1)} ?: {m2.group(2)}" + ' }}'
        return '{{ ' + convert_expr(b) + ' }}'

    def sub_tag(m):
        b = fix_common(m.group(1))
        return '{% ' + b.strip() + ' %}'

    s = re.sub(r'\{\{(.*?)\}\}', sub_print, s, flags=re.S)
    s = re.sub(r'\{%-?(.*?)-?%\}', sub_tag, s, flags=re.S)
    s = s.replace(".html'", ".twig'")
    s = re.sub(r'\{%-?\s*elif\b', lambda m: m.group(0).replace('elif', 'elseif'), s)   # Twig は elseif
    return s


# 画面ごとの個別の直し（辞書のキーがタプルのもの、リストを作り足すもの）
SPECIAL = {
    'history.html': [
        ("{% for (month, day), orders in grouped.items() %}",
         "{% for g in grouped %}{% set month = g.month %}{% set day = g.day %}{% set orders = g.orders %}"),
    ],
    'settings_orders.html': [
        ("{% for (month, day), orders in grouped.items() %}",
         "{% for g in grouped %}{% set month = g.month %}{% set day = g.day %}{% set orders = g.orders %}"),
    ],
    'usage.html': [
        ("  {% set seen = [] %}\n  {% for (sup, cat) in grouped.keys() %}{% if cat not in seen %}{% set _ = seen.append(cat) %}\n"
         "  <button type=\"button\" data-cat=\"{{ cat }}\">{{ cat if cat else '（未分類）' }}</button>\n  {% endif %}{% endfor %}",
         "  {% for cat in usage_cats %}\n  <button type=\"button\" data-cat=\"{{ cat }}\">{{ cat ?: '（未分類）' }}</button>\n  {% endfor %}"),
        ("{% for (sup, cat), prods in grouped.items() %}",
         "{% for g in grouped %}{% set sup = g.sup %}{% set cat = g.cat %}{% set prods = g.prods %}"),
    ],
}


def main():
    os.makedirs(DST, exist_ok=True)
    for f in sorted(glob.glob(os.path.join(SRC, '*.html'))):
        name = os.path.basename(f)
        if name in SKIP:
            continue
        s = open(f, encoding='utf-8').read()
        for old, new in SPECIAL.get(name, []):
            assert old in s, (name, old[:60])
            s = s.replace(old, new)
        s = s.replace('.items() %}', ' %}').replace('.keys() %}', '|keys %}')
        s = convert_text(s)
        out = os.path.join(DST, name[:-5] + '.twig')
        open(out, 'w', encoding='utf-8').write(s)
        print('変換:', name, '→', os.path.basename(out))


if __name__ == '__main__':
    main()
