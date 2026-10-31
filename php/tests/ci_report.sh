#!/usr/bin/env bash
# 失敗の理由を、GitHub Actions の「注釈(annotation)」として残す（ログインしなくても読める）
# 使い方: bash php/tests/ci_report.sh 見出し ファイル
title="$1"; file="$2"
body=$(tail -c 3800 "$file" | sed -e 's/%/%25/g' -e ':a;N;$!ba;s/\r//g;s/\n/%0A/g')
echo "::error title=${title}::${body}"
