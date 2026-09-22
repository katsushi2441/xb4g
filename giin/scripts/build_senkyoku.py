#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""衆議院小選挙区（289）ごとに、公開データの数字を1ページに並べる「選挙区ダッシュボード」を作る。

  /usr/bin/python3 scripts/build_senkyoku.py

入力
  data/senkyoku_wiki.md        … Wikipedia「衆議院小選挙区制選挙区一覧」（2022年区割り）を r.jina.ai で落とした写し。
                                  区域の文（市区町村の並び）と、有権者数・一票の格差の表を読む
  data/geolonia_ja.json        … 市区町村名の正本（郡・区つき。Geolonia japanese-addresses）
  data/khazard_muni_stats.json … 土砂災害警戒区域の市町村別集計（khazard の muni_stats を書き出したもの）
  各製品の SQLite（krefuge / ktsunami / kriskarea / kkaigo / khoudei / kghome / kshuro）と kgakudo の JSON

出力（data/giin.sqlite）
  senkyoku       … 選挙区（key=aichi-1 など）・区域の文・市区町村の一覧（JSON）・有権者数・格差
  senkyoku_stat  … 選挙区×指標の値。partial=1 は「市の一部だけが選挙区なのに、市全体の数しか無い」印

数え方の線
  - 政令市の区までしか選挙区に入っていない場合、区ごとに数えられるデータ（事業所）は区で数え、
    市までしか無いデータ（避難所・土砂・津波・危険区域・学童）は市全体の数を partial=1 で出す。
  - 「一部」「〜に属しない地域」など町丁で割れている市は、市全体を入れて partial=1。
  - 郡は Geolonia の一覧で町村に展開する。
  - 無いものは 0 にしない（行を作らない）。画面は「収録なし」と出す。
"""
from __future__ import annotations

import json
import os
import re
import sqlite3
import sys
from collections import defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, "data")
W = "/home/kojima/work"

PREFS = ['北海道','青森県','岩手県','宮城県','秋田県','山形県','福島県','茨城県','栃木県','群馬県','埼玉県','千葉県','東京都','神奈川県','新潟県','富山県','石川県','福井県','山梨県','長野県','岐阜県','静岡県','愛知県','三重県','滋賀県','京都府','大阪府','兵庫県','奈良県','和歌山県','鳥取県','島根県','岡山県','広島県','山口県','徳島県','香川県','愛媛県','高知県','福岡県','佐賀県','長崎県','熊本県','大分県','宮崎県','鹿児島県','沖縄県']
SLUGS = ['hokkaido','aomori','iwate','miyagi','akita','yamagata','fukushima','ibaraki','tochigi','gunma','saitama','chiba','tokyo','kanagawa','niigata','toyama','ishikawa','fukui','yamanashi','nagano','gifu','shizuoka','aichi','mie','shiga','kyoto','osaka','hyogo','nara','wakayama','tottori','shimane','okayama','hiroshima','yamaguchi','tokushima','kagawa','ehime','kochi','fukuoka','saga','nagasaki','kumamoto','oita','miyazaki','kagoshima','okinawa']
PREF_SLUG = dict(zip(PREFS, SLUGS))
# rstrip は文字集合で削るので「京都府」が「京」になる。末尾1文字を落とす
SHORT = {p: ('北海道' if p == '北海道' else p[:-1]) for p in PREFS}


def load_master():
    """Geolonia: pref -> [市区町村名]。郡→町村、市→区 の展開表も作る。"""
    ja = json.load(open(os.path.join(DATA, "geolonia_ja.json"), encoding="utf-8"))
    gun = defaultdict(lambda: defaultdict(list))   # pref -> 郡名 -> [町村名(郡なし)]
    wards = defaultdict(lambda: defaultdict(list)) # pref -> 市名 -> [区名]
    names = defaultdict(set)                       # pref -> {市区町村名(郡なし・区つき)}
    for pref, cities in ja.items():
        for c in cities:
            m = re.match(r'^(.+?郡)(.+)$', c)
            if m and not c.endswith('区'):
                gun[pref][m.group(1)].append(m.group(2)); names[pref].add(m.group(2)); continue
            m = re.match(r'^(.+?市)(.+区)$', c)
            if m:
                wards[pref][m.group(1)].append(m.group(2)); names[pref].add(c); names[pref].add(m.group(1)); continue
            names[pref].add(c)
    return gun, wards, names


def parse_wiki():
    lines = open(os.path.join(DATA, "senkyoku_wiki.md"), encoding="utf-8").read().split("\n")
    pref = None; out = []
    for l in lines:
        s = l.strip()
        m = re.match(r'^(北海道|.+?[都道府県])編集$', s)
        if m and m.group(1) in PREFS:
            pref = m.group(1); continue
        m = re.match(r'^第(\d+)区\t([^\t]+)(?:\t([^\t]*))?', s)
        if m and pref:
            area = re.sub(r'\[注 \d+\]', '', m.group(2)).strip()
            out.append({"pref": pref, "no": int(m.group(1)), "area": area, "note": (m.group(3) or '').strip()})
    voters = {}
    for l in lines:
        m = re.match(r'^(.+?[都道府県])第(\d+)区\t([\d,]+)人\t([\d.]+)', l.strip())
        if m and m.group(1) in PREFS:
            voters[(m.group(1), int(m.group(2)))] = (int(m.group(3).replace(',', '')), float(m.group(4)))
    return out, voters


TOKYO_ISLANDS = {'大島支庁管内': ['大島町', '利島村', '新島村', '神津島村'], '三宅支庁管内': ['三宅村', '御蔵島村'],
                 '八丈支庁管内': ['八丈町', '青ヶ島村'], '小笠原支庁管内': ['小笠原村']}
DIRECTION = re.compile(r'(南東|北東|南西|北西|東|西|南|北)部$')


def nk(s: str) -> str:
    """ケ／ヶ／ヵ の表記ゆれを寄せる（龍ケ崎・袖ケ浦・鎌ケ谷・駒ケ根）。"""
    return s.replace('ヶ', 'ケ').replace('ヵ', 'カ')


def expand_area(pref, area, gun, wards, names, extra_wards, shinko):
    """区域の文 → [{name, wards:[...]|None, partial:bool}]。name は 市/町/村（郡なし）。"""
    out = []; unknown = []
    nmap = {nk(n): n for n in names[pref]}
    # 括弧の中（「3区に属しない地域」「本庁管内、○○支所管内」「旧○○村域」）は町丁の線引きなので、
    # 「一部」の印にして落としてから 、 で切る（括弧の中に 、 があるため）
    partial_all = bool(re.search(r'（[^）]*）|の一部', area))
    text = re.sub(r'（[^）]*）', '〔部分〕', area)
    for tok in [t for t in re.split(r'、', text) if t.strip()]:
        partial = '〔部分〕' in tok or 'の一部' in tok
        tok = tok.replace('〔部分〕', '').replace('の一部', '').strip()
        if not tok:
            continue
        if tok in TOKYO_ISLANDS:
            for t in TOKYO_ISLANDS[tok]: out.append({"name": t, "wards": None, "partial": False})
            continue
        if pref == '北海道' and tok in shinko:
            for t in shinko[tok]:
                if nk(t) in nmap: out.append({"name": nmap[nk(t)], "wards": None, "partial": False})
                else: unknown.append(tok + '→' + t)
            continue
        # 「世田谷区南東部」「八王子市東部」＝町丁で割れている
        m = DIRECTION.search(tok)
        if m and nk(tok[:m.start()]) in nmap:
            out.append({"name": nmap[nk(tok[:m.start()])], "wards": None, "partial": True}); continue
        # 「横浜市中区・磯子区・金沢区」／「さいたま市見沼区・浦和区」
        m = re.match(r'^(.+?市)((?:.+?区)(?:・.+?区)*)$', tok)
        if m and (m.group(1) in wards[pref] or m.group(1) in extra_wards[pref]):
            allw = set(wards[pref].get(m.group(1), [])) | set(extra_wards[pref].get(m.group(1), []))
            ws = [w for w in m.group(2).split('・')]
            bad = [w for w in ws if w not in allw]
            if bad: unknown.append(tok + '→' + '・'.join(bad))
            out.append({"name": m.group(1), "wards": [w for w in ws if w in allw], "partial": partial or len(ws) < len(allw)})
            continue
        # 「北群馬郡」「入間郡三芳町」「入間郡毛呂山町・越生町」「中郡大磯町」
        m = re.match(r'^(.+?郡)(.*)$', tok)
        if m and m.group(1) in gun[pref]:
            rest = m.group(2)
            towns = [t for t in rest.split('・') if t] if rest else list(gun[pref][m.group(1)])
            for t in towns:
                if nk(t) in nmap: out.append({"name": nmap[nk(t)], "wards": None, "partial": partial})
                else: unknown.append(tok + '→' + t)
            continue
        for t in tok.split('・'):
            t = t.strip()
            if not t: continue
            if nk(t) in nmap: out.append({"name": nmap[nk(t)], "wards": None, "partial": partial})
            else: unknown.append(t)
    return out, unknown


def code_maps():
    """市区町村名 → 5桁コード（市町村は krefuge、政令市の区は kkaigo の area_code）。"""
    city = {}
    c = sqlite3.connect(os.path.join(W, "krefuge/data/krefuge.db"))
    for code, pref, muni in c.execute("SELECT muni_code, pref, muni FROM muni_stats"):
        city[(pref, muni)] = code
    ward = {}; extra_wards = defaultdict(lambda: defaultdict(list))
    k = sqlite3.connect(os.path.join(W, "kkaigo/php/kkaigo_data/kkaigo.sqlite"))
    for pref, cty, ac in k.execute("SELECT DISTINCT pref, city, area_code FROM offices WHERE area_code<>''"):
        if ac and len(ac) >= 5: ward[(pref, cty)] = ac[:5]
        m = re.match(r'^(.+?市)(.+区)$', cty)
        if m: extra_wards[pref][m.group(1)].append(m.group(2))
    return city, ward, extra_wards


def main():
    gun, wards, names = load_master()
    rows, voters = parse_wiki()
    city_code, ward_code, extra_wards = code_maps()
    shinko = json.load(open(os.path.join(DATA, "hokkaido_shinkokyoku.json"), encoding="utf-8"))
    print(f"選挙区 {len(rows)} / 有権者表 {len(voters)}")

    # ---- 指標の元データ -------------------------------------------------
    khz = {r['muni_code']: r for r in json.load(open(os.path.join(DATA, "khazard_muni_stats.json"), encoding="utf-8"))}
    ref = {}
    c = sqlite3.connect(os.path.join(W, "krefuge/data/krefuge.db")); c.row_factory = sqlite3.Row
    for r in c.execute("SELECT * FROM muni_stats"): ref[r['muni_code']] = dict(r)
    tsu = {}
    c = sqlite3.connect(os.path.join(W, "ktsunami/data/ktsunami.db")); c.row_factory = sqlite3.Row
    for r in c.execute("SELECT * FROM muni_stats"): tsu[r['muni_code']] = dict(r)
    rsk = {}
    c = sqlite3.connect(os.path.join(W, "kriskarea/data/kriskarea.sqlite")); c.row_factory = sqlite3.Row
    for r in c.execute("SELECT * FROM muni_stats"): rsk[r['admin_code']] = dict(r)
    gak = {}
    gj = json.load(open(os.path.join(W, "kgakudo/php/kgakudo_data/gakudo_2025.json"), encoding="utf-8"))
    for a in gj['areas']:
        if a.get('kind') != '都道府県': gak[a['name']] = a
    # 事業所系: (pref, city名) で数える（区名つき）。offices の city は「横浜市中区」「三浦市」の形
    def office_counts(slug, kinds):
        c = sqlite3.connect(os.path.join(W, f"{slug}/php/{slug}_data/{slug}.sqlite"))
        now = defaultdict(int); gone = defaultdict(int)
        q = ",".join("?" * len(kinds))
        for pref, cty, n in c.execute(f"SELECT pref, city, count(*) FROM offices WHERE kind IN ({q}) GROUP BY pref, city", kinds): now[(pref, cty)] = n
        for pref, cty, n in c.execute(f"SELECT pref, city, count(*) FROM gone WHERE kind IN ({q}) GROUP BY pref, city", kinds): gone[(pref, cty)] = n
        return now, gone
    OFF = {
        'houmon': office_counts('kkaigo', ['訪問介護']),
        'caremane': office_counts('kkaigo', ['居宅介護支援']),
        'houdei': office_counts('khoudei', ['放課後等デイサービス']),
        'jihatsu': office_counts('khoudei', ['児童発達支援']),
        'ghome': office_counts('kghome', ['共同生活援助']),
        'shuroA': office_counts('kshuro', ['就労継続支援A型']),
        'shuroB': office_counts('kshuro', ['就労継続支援B型']),
    }

    def muni_keys(pref, m):
        """その市区町村（区つきなら区ごと）の (表示名, 5桁コード or None, 事業所用の city名) の一覧。"""
        if m['wards']:
            return [(m['name'] + w, ward_code.get((pref, m['name'] + w)), m['name'] + w) for w in m['wards']]
        return [(m['name'], city_code.get((pref, m['name'])), m['name'])]

    db = sqlite3.connect(os.path.join(DATA, "giin.sqlite"))
    db.executescript("""
    DROP TABLE IF EXISTS senkyoku; DROP TABLE IF EXISTS senkyoku_stat;
    CREATE TABLE senkyoku (key TEXT PRIMARY KEY, pref TEXT, pref_slug TEXT, no INTEGER, name TEXT, area TEXT, note TEXT,
                           voters INTEGER, gap REAL, munis_json TEXT, unknown_json TEXT);
    CREATE TABLE senkyoku_stat (key TEXT, metric TEXT, value INTEGER, partial INTEGER, detail TEXT, PRIMARY KEY (key, metric));
    CREATE INDEX senkyoku_pref ON senkyoku(pref_slug, no);
    """)
    unk_all = []
    for r in rows:
        pref = r['pref']; key = f"{PREF_SLUG[pref]}-{r['no']}"
        munis, unknown = expand_area(pref, r['area'], gun, wards, names, extra_wards, shinko)
        unk_all += [(key, u) for u in unknown]
        v = voters.get((pref, r['no']), (None, None))
        stats = defaultdict(lambda: {"value": 0, "partial": 0, "detail": []})
        seen_city = set()
        for m in munis:
            # 事業所系（区で数えられる）
            for disp, code, cty in muni_keys(pref, m):
                for k, (now, gone) in OFF.items():
                    if (pref, cty) in now or (pref, cty) in gone:
                        stats[k]["value"] += now.get((pref, cty), 0)
                        stats[k + "_gone"]["value"] += gone.get((pref, cty), 0)
                        stats[k]["detail"].append([disp, now.get((pref, cty), 0)])
                        stats[k + "_gone"]["detail"].append([disp, gone.get((pref, cty), 0)])
                        if m['partial'] and not m['wards']:
                            stats[k]["partial"] = 1; stats[k + "_gone"]["partial"] = 1
            # 市までしか無いデータ（区が入っていれば市全体を1回・partial）
            ccode = city_code.get((pref, m['name']))
            if not ccode or (pref, m['name']) in seen_city: continue
            seen_city.add((pref, m['name']))
            part = 1 if (m['wards'] or m['partial']) else 0
            if ccode in khz:
                z = khz[ccode]
                for k2, col in (('dosha', 'zones'), ('dosha_red', 'red'), ('dosha_yellow', 'yellow')):
                    stats[k2]["value"] += int(z.get(col) or 0); stats[k2]["partial"] |= part; stats[k2]["detail"].append([m['name'], int(z.get(col) or 0)])
            if ccode in ref:
                z = ref[ccode]
                for k2, col in (('shelters', 'shelters'), ('shelters_flood', 'flood'), ('shelters_landslide', 'landslid'), ('shelters_tsunami', 'tsunami')):
                    stats[k2]["value"] += int(z.get(col) or 0); stats[k2]["partial"] |= part; stats[k2]["detail"].append([m['name'], int(z.get(col) or 0)])
            if ccode in tsu:
                z = tsu[ccode]
                stats['tsunami_inundated']["value"] += int(z.get('inundated') or 0); stats['tsunami_inundated']["partial"] |= part
                stats['tsunami_inundated']["detail"].append([m['name'], int(z.get('inundated') or 0), z.get('max_label') or ''])
            if ccode in rsk:
                z = rsk[ccode]
                stats['riskarea']["value"] += int(z.get('areas') or 0); stats['riskarea']["partial"] |= part; stats['riskarea']["detail"].append([m['name'], int(z.get('areas') or 0)])
            if m['name'] in gak:
                z = gak[m['name']]
                stats['gakudo_waiting']["value"] += int(z.get('waiting') or 0); stats['gakudo_waiting']["partial"] |= part
                stats['gakudo_waiting']["detail"].append([m['name'], int(z.get('waiting') or 0), int(z.get('registered') or 0)])
        db.execute("INSERT INTO senkyoku VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                   (key, pref, PREF_SLUG[pref], r['no'], f"{SHORT[pref]}{r['no']}区", r['area'], r['note'], v[0], v[1],
                    json.dumps(munis, ensure_ascii=False), json.dumps(unknown, ensure_ascii=False)))
        for k, s in stats.items():
            db.execute("INSERT INTO senkyoku_stat VALUES (?,?,?,?,?)", (key, k, s["value"], s["partial"], json.dumps(s["detail"], ensure_ascii=False)))
    db.execute("INSERT OR REPLACE INTO meta (k, v) VALUES ('senkyoku_built', date('now'))")
    db.commit()
    n = db.execute("SELECT count(*) FROM senkyoku").fetchone()[0]
    print(f"→ senkyoku {n} 件 / stat {db.execute('SELECT count(*) FROM senkyoku_stat').fetchone()[0]} 行")
    if unk_all:
        print(f"!! 市区町村名に当たらなかったもの {len(unk_all)} 件:")
        for k, u in unk_all[:60]: print("  ", k, u)


if __name__ == "__main__":
    main()
