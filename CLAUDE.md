# AIエージェントへの指示

**このファイルを最初に読んでください。**（Claude Code は `CLAUDE.md`、Codex は `AGENTS.md` を読みます。2枚は同じ内容です。）

これは **Kurage Track & GEO & SEO（ktrackgeoseo）** です。あなたが設置と初期設定を行い、人が使える状態にします。

## あなたがやること

1. この文書と `INSTALL.md` を最後まで読む
2. `INSTALL.md` の手順を上から実行し、各手順の「確認」を必ず通す
3. 計測を入れるときは `skills/ktrackgeo-install/SKILL.md` に従う
4. 全部通ったら、入口（ktrackgeoseo.php）と計測の画面のURLを人に伝える

**確認していないものを「できました」と言わないでください。**

## 骨格

```
入口  ktrackgeoseo.php         URLを1回入れて、下の3つの診断を続けてかけ、1枚にまとめる
SEO   kseo.php                 Google検索向け（10カテゴリ・生成AIを使わない）
AEO   ktrackgeoseo_aeo.php     AIの回答に引用されやすいか（7観点・生成AIを使わない。Kurage GEO の判定を移植）
GEO   ktrackgeo_geo.php        AI検索に読まれるか（8観点・生成AIを使わない）
実測  ktrackgeo.php            実際に誰が来たか（人／AI／検索エンジン）
画面  ktrackgeo_dashboard.php  計測を見る
```

## 勝手に変えてはいけないこと

- **診断に生成AIを使わない。**結果が実行ごとにぶれ、「直したのに点が下がった」が起きます
- **kseo.php・ktrackgeoseo_aeo.php・ktrackgeo_geo.php の判定の中身を、入口側の都合で変えない。**入口はまとめ役です。AEO を直したら `scripts/check_aeo_parity.py` で Kurage GEO と同じ点が出るか確かめる
- **データの置き場（kseo_data/・ktg_data/・ktgs_data/）の `.htaccess` を消さない**
- **計測でボットを捨てない。**捨てるとAIに読まれているかが永久に分かりません
- **IPを平文で保存しない**
- **外部APIを呼ばない**
