#!/usr/bin/env python3
"""監視メールが「届いたこと」を受信箱（Gmail IMAP）で確かめる。送ったログではなく受信箱を見る（決まりごと）。
  /usr/bin/python3 scripts/check_watch_mail.py [--since HH:MM] [--grep 文字列]
"""
import email, imaplib, re, sys, datetime
from email.header import decode_header, make_header
MAIL = "katsushi2441@gmail.com"
pw = ""
for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
    if ln.startswith("GMAIL_APP_PASSWORD="): pw = ln.split("=", 1)[1].strip().strip("\"'").replace(" ", "")
since = sys.argv[sys.argv.index("--since") + 1] if "--since" in sys.argv else "00:00"
needle = sys.argv[sys.argv.index("--grep") + 1] if "--grep" in sys.argv else ""
M = imaplib.IMAP4_SSL("imap.gmail.com"); M.login(MAIL, pw); M.select("INBOX", readonly=True)
today = datetime.date.today().strftime("%d-%b-%Y")
_, data = M.search(None, f'(SINCE {today} FROM "{MAIL}")')
ids = data[0].split()[-12:]
for i in reversed(ids):
    _, d = M.fetch(i, "(RFC822)"); m = email.message_from_bytes(d[0][1])
    sub = str(make_header(decode_header(m.get("Subject", "")))); dt = email.utils.parsedate_to_datetime(m.get("Date"))
    if dt.astimezone().strftime("%H:%M") < since: continue
    body = ""
    for part in (m.walk() if m.is_multipart() else [m]):
        if part.get_content_type() == "text/plain": body = part.get_payload(decode=True).decode(part.get_content_charset() or "utf-8", "ignore"); break
    hit = (needle in body) if needle else None
    print(dt.astimezone().strftime("%m/%d %H:%M"), "|", sub[:60], "|", ("含む" if hit else "含まない") if needle else f"{len(body)}字")
    if needle and hit:
        i0 = body.find(needle); print("   …", body[i0:i0 + 420].replace("\n", "\n   "))
M.logout()
