#!/usr/bin/env python3
"""/giin/llms.txt を作る（AI検索向けの要約）。trackers.json と giin.sqlite の件数から。  /usr/bin/python3 scripts/build_llms.py"""
import json, os, sqlite3
R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
T = json.load(open(os.path.join(R, 'data', 'trackers.json'), encoding='utf-8'))
T = T if isinstance(T, list) else T.get('trackers', T)
db = sqlite3.connect(os.path.join(R, 'data', 'giin.sqlite'))
st = {r[0]: r[1:] for r in db.execute("SELECT tracker, SUM(kind='q'), SUM(kind='gov'), MIN(date), MAX(date) FROM tracker_speech GROUP BY tracker")}
L = ['# 愛知の国会議員 発言ログ／国会トラッカー', '',
     '> 国立国会図書館の国会会議録検索システムから、ことがらごとに「だれが国会で質問し、政府が何と答えたか」を機械的に集めて並べるサイト。要約・賛否の判定はしない。運営は株式会社エクスブリッジ（名古屋市）。', '',
     '## 何が分かるか',
     '- 国会トラッカー：ことがら（例: 電子カルテ義務化、不登校、年収の壁）ごとに、全国の国会議員の質疑と政府答弁の件数・年ごとの推移・取り上げた議員・直近の答弁',
     '- 議員ページ：愛知県選出の国会議員45人が、いつ・どの会議で・何を質問したか',
     '- 数字はすべて会議録の発言を語で数えたもの。発言の文脈は各ページの会議録リンクで確かめる', '',
     '## 国会トラッカー一覧（質疑件数・答弁件数・期間）']
for t in T:
    s = st.get(t['key'])
    if not s:
        continue
    L.append(f"- [{t['name']}](https://xb4g.com/giin/tracker/{t['key']}): 質疑{s[0]:,}件・政府答弁{s[1]:,}件（{s[2][:10]}〜{s[3][:10]}）")
L += ['', '## 注意', '- 話者の立場（質疑・答弁）は会議録の話者表記から機械的に分けている', '- 語を含む発言を数えるので、別の文脈での言及も含まれる', '']
open(os.path.join(R, 'llms.txt'), 'w', encoding='utf-8').write('\n'.join(L))
print(len(L), 'lines')
