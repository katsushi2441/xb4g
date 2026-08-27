# xb4g.com — 第4世代エクスブリッジ リンク集

株式会社エクスブリッジの全サイト・全プロダクトを紹介するリンク集。Decap CMSで運用。

- 配信: heteml `web/xb4g_com/`(index.phpが `content/` のJSONを描画)
- 編集: https://xb4g.com/admin/ (Decap CMS・GitHubログイン)
- 反映: Decapで保存 → このリポジトリにcommit → GitHub webhookが `deploy.php` を叩き `content/` を同期
- コード変更(index.php等)はFTPでデプロイ(deploy.phpはcontent/とimages/uploads/しか同期しない)
