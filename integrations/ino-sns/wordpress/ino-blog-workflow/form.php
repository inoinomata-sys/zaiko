<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#f4f5f1">
<title>ブログ下書き | INO</title>
<style>
:root{font-family:system-ui,-apple-system,"Hiragino Sans","Yu Gothic",sans-serif;color:#19261f;background:#f1f3ee;color-scheme:light;line-height:1.65;--green:#244d39;--dim:#4e5d54;--line:#d0d9d1}
*{box-sizing:border-box}
body{margin:0;min-width:0;padding:32px 18px}
main{max-width:490px;margin:auto;background:#fff;border:1px solid #e3e7df;border-radius:22px;padding:32px;box-shadow:0 16px 55px #223c2610}
header{border-bottom:1px solid #e7ebe5;padding-bottom:13px;margin-bottom:27px}
.brand{letter-spacing:.25em;font-size:1.25rem;font-weight:650;color:var(--green)}
h1{font-size:clamp(1.65rem,5vw,2rem);line-height:1.3;margin:0 0 10px;letter-spacing:-.03em}
.description{margin:0 0 28px;color:var(--dim);font-size:.94rem}
.label-row{display:flex;align-items:center;gap:10px;margin-bottom:9px;flex-wrap:wrap}
label,.label{font-weight:700}
.tag{font-size:.78rem;font-weight:650;letter-spacing:.025em;background:#edf2ed;color:#3b5142;padding:1px 7px;border-radius:5px}
textarea{width:100%;min-height:154px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:13px 14px;color:inherit;font:inherit;resize:vertical}
textarea::placeholder{color:#66776a}
textarea:focus-visible,button:focus-visible,.picker:focus-within{outline:2px solid #456e58;outline-offset:3px}
.example{border:0;background:none;color:var(--green);text-decoration:underline;text-underline-offset:3px;padding:8px 0 2px;font:inherit;font-size:.875rem;cursor:pointer}
.group{margin-bottom:24px}
.choices{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:24px}
.choice{min-width:0}
.choice-menu{grid-column:1/-1}
select{appearance:none;width:100%;min-height:48px;border:1px solid var(--line);border-radius:10px;padding:8px 34px 8px 12px;background:linear-gradient(45deg,transparent 50%,var(--green) 50%) calc(100% - 18px) 50%/5px 5px no-repeat,linear-gradient(135deg,var(--green) 50%,transparent 50%) calc(100% - 13px) 50%/5px 5px no-repeat #fff;color:inherit;font:inherit}
select:focus-visible{outline:2px solid #456e58;outline-offset:3px}
.item-input{width:100%;min-height:48px;border:1px solid var(--line);border-radius:10px;padding:8px 12px;color:inherit;font:inherit}
.item-input::placeholder{color:#66776a}
.item-input:focus-visible{outline:2px solid #456e58;outline-offset:3px}
.concerns{min-width:0;border:0;margin:0 0 24px;padding:0}
.concerns legend{margin-bottom:10px;padding:0;font-weight:700}
.concerns legend .tag{margin-left:8px}
.concern-options{display:flex;flex-wrap:wrap;gap:8px}
.concern{display:flex;align-items:center;gap:7px;min-height:44px;padding:7px 10px;border:1px solid var(--line);border-radius:9px;font-size:.875rem;font-weight:550;cursor:pointer}
.concern:has(:checked){border-color:#45755b;background:#ecf4ed;color:var(--green)}
.concern input{width:16px;height:16px;margin:0;accent-color:var(--green)}
.concern:focus-within{outline:2px solid #456e58;outline-offset:2px}
.picker{position:relative;display:grid;place-items:center;border:1px solid var(--line);border-radius:12px;min-height:50px;color:var(--green);cursor:pointer;background:#f8faf7}
.picker input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.hint{margin:8px 0 0;color:var(--dim);font-size:.88rem}
.primary{width:100%;border:0;border-radius:12px;padding:14px 12px;min-height:54px;background:var(--green);color:#fff;font:inherit;font-weight:700;cursor:pointer}
.primary:disabled{opacity:.65;cursor:default}
.primary:hover{background:#1d402f}
.notice{margin:10px 0 0;text-align:center;font-size:.84rem;color:var(--dim)}
.error{color:#9a2c23;font-size:.875rem;margin:6px 0 0}
.preview{margin-top:24px;border-top:1px solid #e0e7e0;padding-top:19px}
.preview h2{font-size:1.15rem;margin:0 0 6px}
.preview p{margin:8px 0}
.preview .memo{white-space:pre-wrap;overflow-wrap:anywhere;background:#edf2ed;border-radius:10px;padding:12px 14px}
.details{color:var(--green);font-size:.92rem;font-weight:650;overflow-wrap:anywhere}
.photo{display:block;max-width:100%;max-height:210px;margin-top:12px;border-radius:10px;object-fit:contain}
[hidden]{display:none!important}
@media(max-width:520px){body{padding:0;background:#fff}main{border:0;border-radius:0;box-shadow:none;padding:23px 20px 36px}}
</style>
</head>
<body>
<main>
<header><span class="brand">INO</span></header>
<h1>ブログ下書き</h1>
<p class="description">施術メモから、お客様向けの記事を準備します。</p>
<form id="draft-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
  <?php wp_nonce_field('ino_blog_submit', '_wpnonce', false); ?>
  <input type="hidden" name="action" value="ino_blog_submit">
  <input type="hidden" name="request_id" value="<?php echo esc_attr($request_id); ?>">
  <div class="choices">
    <div class="choice">
      <div class="label-row"><label for="age">年代</label><span class="tag">任意</span></div>
      <select id="age" name="age">
        <option value="">選択しない</option>
        <option>10代以下</option>
        <option>20代</option>
        <option>30代</option>
        <option>40代</option>
        <option>50代</option>
        <option>60代</option>
        <option>70代以上</option>
      </select>
    </div>
    <div class="choice">
      <div class="label-row"><label for="gender">性別</label><span class="tag">任意</span></div>
      <select id="gender" name="gender">
        <option value="">選択しない</option>
        <option>女性</option>
        <option>男性</option>
        <option>その他</option>
      </select>
    </div>
    <div class="choice choice-menu">
      <div class="label-row"><label for="menu">メニュー</label><span class="tag">任意</span></div>
      <select id="menu" name="menu">
        <option value="">選択しない</option>
        <option>縮毛矯正</option>
        <option>前髪の縮毛矯正</option>
        <option>カット</option>
        <option>カラー</option>
        <option>相談のみ</option>
        <option>その他</option>
      </select>
    </div>
  </div>
  <fieldset class="concerns">
    <legend>髪のお悩み <span class="tag">任意・複数選択可</span></legend>
    <div class="concern-options">
      <label class="concern"><input type="checkbox" name="concerns[]" value="うねり">うねり</label>
      <label class="concern"><input type="checkbox" name="concerns[]" value="広がり">広がり</label>
      <label class="concern"><input type="checkbox" name="concerns[]" value="前髪のくせ">前髪のくせ</label>
      <label class="concern"><input type="checkbox" name="concerns[]" value="まとまりにくい">まとまりにくい</label>
      <label class="concern"><input type="checkbox" name="concerns[]" value="パサつき・ダメージ">パサつき・ダメージ</label>
      <label class="concern"><input type="checkbox" name="concerns[]" value="白髪">白髪</label>
      <label class="concern"><input type="checkbox" name="concerns[]" value="その他">その他</label>
    </div>
  </fieldset>
  <div class="group">
    <div class="label-row"><label for="memo">施術メモ</label><span class="tag">必須</span></div>
    <textarea id="memo" name="memo" required maxlength="1500" placeholder="お客様のお悩みや、施術・提案したことを入力"></textarea>
    <button id="example" class="example" type="button">記入例を入れる</button>
  </div>
  <div class="group">
    <div class="label-row"><label for="item">おすすめアイテム</label><span class="tag">任意</span></div>
    <input class="item-input" id="item" name="item" type="text" maxlength="100" list="item-examples" placeholder="例　洗い流さないヘアミルク">
    <datalist id="item-examples">
      <option value="洗い流さないヘアミルク"></option>
      <option value="ヘアオイル"></option>
      <option value="シャンプー"></option>
      <option value="洗い流すトリートメント"></option>
    </datalist>
  </div>
  <div class="group">
    <div class="label-row"><label for="photo">掲載写真</label><span class="tag">任意</span></div>
    <label class="picker"><span id="photo-label">写真を選ぶ</span><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" aria-label="掲載写真を選ぶ" aria-describedby="photo-hint"></label>
    <p class="hint" id="photo-hint">掲載許可を得た写真を選んでください。JPEG・PNG・WebP、3MBまで。</p>
    <p id="photo-error" class="error" role="alert" hidden></p>
    <img id="photo-preview" class="photo" alt="選択した掲載写真" hidden>
  </div>
  <button class="primary" type="submit">下書きを作る</button>
  <p class="notice">下書きができると、ブログ管理室からLINEが届きます。</p>
</form>
<section id="result" class="preview" aria-live="polite" tabindex="-1" hidden>
  <h2 id="result-title"></h2>
  <p id="result-message"></p>
</section>
</main>
<script>
const form = document.querySelector('#draft-form');
const memo = document.querySelector('#memo');
const photo = document.querySelector('#photo');
const result = document.querySelector('#result');
const preview = document.querySelector('#photo-preview');
const photoLabel = document.querySelector('#photo-label');
const photoError = document.querySelector('#photo-error');
const button = form.querySelector('button[type="submit"]');
let sending = false;
let accepted = false;
let photoUrl;
const sample = '雨の日に前髪がうねるとご相談。前髪だけの縮毛矯正を提案しました。今日は施術せず、根元から乾かすコツをお伝えしました。';

document.querySelector('#example').addEventListener('click', () => {
  memo.value = sample;
  memo.setCustomValidity('');
  result.hidden = true;
  memo.focus();
});
memo.addEventListener('input', () => {
  memo.setCustomValidity('');
  result.hidden = true;
});
form.addEventListener('change', () => {
  result.hidden = true;
});
document.querySelector('#item').addEventListener('input', () => {
  result.hidden = true;
});
photo.addEventListener('change', () => {
  if (photoUrl) URL.revokeObjectURL(photoUrl);
  photoUrl = undefined;
  preview.hidden = true;
  preview.removeAttribute('src');
  photoLabel.textContent = '写真を選ぶ';
  photoError.hidden = true;

  const file = photo.files[0];
  if (!file) return;
  if (file.size > 3 * 1024 * 1024) {
    photo.value = '';
    photoError.textContent = '3MB以内の写真を選んでください。';
    photoError.hidden = false;
    return;
  }
  if (file.type && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    photo.value = '';
    photoError.textContent = '画像ファイルを選んでください。';
    photoError.hidden = false;
    return;
  }

  photoLabel.textContent = '写真を選び直す';
  photoUrl = URL.createObjectURL(file);
  preview.src = photoUrl;
  preview.hidden = false;
});
preview.addEventListener('error', () => {
  preview.hidden = true;
  photoError.textContent = 'この端末で写真の見本を表示できません。';
  photoError.hidden = false;
});
form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (sending || accepted) return;
  if (!memo.value.trim()) {
    memo.setCustomValidity('施術メモを入力してください。');
    memo.reportValidity();
    return;
  }

  const data = new FormData(form);
  const controls = Array.from(form.elements);
  sending = true;
  controls.forEach(control => { control.disabled = true; });
  button.textContent = '送信中…';
  result.hidden = true;
  let message;
  try {
    const response = await fetch(form.getAttribute('action'), {method: 'POST', body: data, credentials: 'same-origin'});
    const json = await response.json();
    accepted = response.ok && json.success === true;
    message = typeof json.data === 'string' ? json.data : 'フォームを開き直して、ログインを確認してください。';
  } catch {
    message = '受付結果を確認できませんでした。LINEに通知が届かなければ、同じ画面から再試行できます。';
  } finally {
    if (!accepted) controls.forEach(control => { control.disabled = false; });
    button.textContent = accepted ? '受付済み' : '下書きを作る';
    sending = false;
  }

  document.querySelector('#result-title').textContent = accepted ? '受け付けました' : '送信を確認できませんでした';
  document.querySelector('#result-message').textContent = message;
  result.hidden = false;
  result.focus();
  result.scrollIntoView({block: 'nearest'});
  if (accepted) {
    let next = document.createElement('a');
    next.href = location.href;
    next.textContent = '次の施術メモを書く';
    result.append(next);
  }
});
</script>
</body>
</html>
