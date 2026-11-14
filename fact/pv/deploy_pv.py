#!/usr/bin/env python3
"""Kurage 国会ファクト PV を公開し、kpv 台帳に登録する（kpvgen ビルド完了後に実行。再実行可）。
  /usr/bin/python3 deploy_pv.py <pv.mp4> <poster.jpg>
1) https://kurage.exbridge.jp/pv/ に mp4 と poster を置く（他の PV と同じ場所）
2) kpv 台帳（kpv_data/videos.json）に id=kokkai-fact を登録（書く前に退避・既存なら seconds を更新）
国会ファクトは kappstore の商品ではないので、商品ページは触らない（page は xb4g.com/fact/）。
"""
import datetime, ftplib, io, json, os, subprocess, sys, urllib.request

PV, POSTER = sys.argv[1], sys.argv[2]
BASE = "https://kurage.exbridge.jp/pv/"
VID = BASE + "kokkai-fact-pv.mp4"
POS = BASE + "kokkai-fact-pv-poster.jpg"
PAGE = "https://xb4g.com/fact/#ref=kpv-kokkai-fact"
HERE = os.path.dirname(os.path.abspath(__file__))
SECS = int(round(float(subprocess.check_output(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", PV]).decode().strip())))

for l in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
    if "=" in l and not l.startswith("#"):
        k, v = l.rstrip("\n").split("=", 1)
        os.environ.setdefault(k, v.strip().strip('"').strip("'"))

f = ftplib.FTP(os.environ["FTP_HOST"], timeout=300)
f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
f.cwd("/web/kurage_exbridge_jp/pv")
f.storbinary("STOR kokkai-fact-pv.mp4", open(PV, "rb"))
f.storbinary("STOR kokkai-fact-pv-poster.jpg", open(POSTER, "rb"))
print(f"  pv/ に配置（{SECS}秒）")
f.cwd("/web/kurage_exbridge_jp/kpv_data")
b = io.BytesIO()
f.retrbinary("RETR videos.json", b.write)
os.makedirs(os.path.join(HERE, "backup"), exist_ok=True)
open(os.path.join(HERE, "backup", f"videos_backup_{datetime.datetime.now():%Y%m%d_%H%M%S}.json"), "wb").write(b.getvalue())
k = json.loads(b.getvalue())
vs = k["videos"] if isinstance(k, dict) else k
n0 = len(vs)
hit = [v for v in vs if v.get("id") == "kokkai-fact"]
if not hit:
    vs.append({"id": "kokkai-fact",
               "title": "Kurage 国会ファクト — 政策の疑問に、国会の会議録の原文で答える（年収の壁・消費税・夫婦別姓・就職氷河期など）",
               "video": VID, "poster": POS, "page": PAGE, "seconds": SECS,
               "tags": ["kokkai-fact", "kokkai", "civic", "giin", "factcheck"],
               "hidden": 0, "created_at": datetime.datetime.now().isoformat()})
    assert len(vs) == n0 + 1
    f.storbinary("STOR videos.json", io.BytesIO(json.dumps(k, ensure_ascii=False, indent=1).encode("utf-8")))
    print(f"  kpv 台帳: kokkai-fact 追加（{n0}→{len(vs)}件）")
elif hit[0].get("seconds") != SECS:
    hit[0]["seconds"] = SECS
    f.storbinary("STOR videos.json", io.BytesIO(json.dumps(k, ensure_ascii=False, indent=1).encode("utf-8")))
    print(f"  kpv 台帳: seconds を {SECS} に更新")
else:
    print("  kpv 台帳: 登録済み")
f.quit()
for u in (VID, POS):
    r = urllib.request.urlopen(urllib.request.Request(u, method="HEAD"), timeout=60)
    print(f"  {r.status} {r.headers.get('Content-Type')} {r.headers.get('Content-Length')}B {u}")
