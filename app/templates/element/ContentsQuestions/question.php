<?php
/**
 * 設問カード（受験画面・練習結果画面の共通表示）
 *
 * @var \App\Model\Entity\ContentsQuestion $contentsQuestion 問題
 * @var int $question_index 設問番号
 * @var array $question_records 設問IDをキーにした結果
 * @var bool $is_record 結果表示モードか
 * @var int $wrong_mode 不正解時の表示モード
 */

/**
 * 解説（explain）の表示タグを組み立てるクロージャ。
 *
 * テンプレート内で global 関数を宣言すると、同一 PHP プロセス内で複数回
 * レンダリングされた場合に "Cannot redeclare function ..." で fatal になるため、
 * ローカル変数にクロージャとして保持する。
 */
$getExplain = function ($explain) {
	$tag = '';

	// 解説（explain）が未入力の設問では NULL が渡るため、文字列に正規化する
	$explain = (string)$explain;
	$check = str_replace(['<p>', '</p>', '<br>'], '', $explain);

	// pタグ、brタグのみの場合、解説を表示しない
	if ($check != '') {
		$tag = sprintf('<div class="correct-text bg-danger">%s : %s</div>', __('解説'), $explain);
	}

	return $tag;
};

$question		= $contentsQuestion;	// 問題情報
$title			= $question['title'];						// 問題のタイトル
$body			= $question['body'];						// 問題文
$question_id	= $question['id'];							// 問題ID

//------------------------------//
//	選択肢用の出力タグの生成	//
//------------------------------//
$option_tag		= '';										// 選択肢用の出力タグ
$option_index	= 1;										// 選択肢番号
// 選択肢（options）と正解（correct）は未入力の設問では NULL になり得るため、
// explode() に渡す前に文字列へ正規化する
$option_list	= explode('|', (string)$question['options']);	// 選択肢リスト
$correct_list	= explode(',', (string)$question['correct']);	// 正解リスト
$answer_list	= [];										// 選択した解答リスト

// 解答済みの場合、解答リストを作成
if (isset($question_records[$question_id])) {
	$answer_list = explode(',', (string)$question_records[$question_id]['answer']);
}

foreach ($option_list as $option) {
	$is_checked = '';

	// テスト結果履歴モードの場合、ラジオボタンを無効化
	$is_disabled = $is_record ? 'disabled' : '';

	// 複数選択(順不同)問題の場合
	if (count($correct_list) > 1) {
		$is_checked = (in_array($option_index, $answer_list)) ? ' checked' : '';

		// 選択肢チェックボックス
		$option_id = sprintf('answer_%s_%d', $question_id, $option_index);
		$option_tag .= sprintf(
			'<label for="%s"><input type="checkbox" id="%s" value="%s" name="answer_%s[]" %s %s> %s</label><br>',
			$option_id,
			$option_id,
			$option_index,
			$question_id,
			$is_checked,
			$is_disabled,
			h($option),
		);
	} else {
		// 解答リストがある場合
		if (count($answer_list) > 0) {
			$is_checked = ($answer_list[0] == $option_index) ? 'checked' : '';
		}

		// 選択肢ラジオボタン
		$option_id = sprintf('answer_%s_%d', $question_id, $option_index);
		$option_tag .= sprintf(
			'<label for="%s"><input type="radio" id="%s" value="%s" name="answer_%s" %s %s> %s</label><br>',
			$option_id,
			$option_id,
			$option_index,
			$question_id,
			$is_checked,
			$is_disabled,
			h($option),
		);
	}

	$option_index++;
}

//------------------------------//
//	正解、解説情報を出力		//
//------------------------------//
$explain_tag	= ''; // 解説用タグ
$correct_tag	= ''; // 正解用タグ
$result_tag		= ''; // 正誤用タグ
$is_correct		= false; // 正誤

// テスト結果表示モードの場合
if ($is_record) {
	// 正誤判定
	if (isset($question_records[$question_id]['is_correct'])) {
		$is_correct = ($question_records[$question_id]['is_correct'] == '1');
	}

	// 正解番号から正解ラベルへ変換
	// 記述式等で correct が空文字の場合、explode で [''] になるため
	// 数値として有効な正解番号のみを対象にする
	$correct_label = ''; // 正解ラベル

	$numeric_corrects = array_filter($correct_list, fn($v) => $v !== '' && ctype_digit((string)$v));

	foreach ($numeric_corrects as $correct_no) {
		$idx = (int)$correct_no - 1;

		if (isset($option_list[$idx])) {
			$correct_label .= ($correct_label == '') ? $option_list[$idx] : ', ' . $option_list[$idx];
		}
	}

	// 正解時は、解説のみを表示
	if ($is_correct) {
		$result_tag  = sprintf('<p>%s<span class="result-currect">%s</span></p>', $this->Html->image('correct.png', ['width' => '60', 'height' => '60']), __('正解'));
		$explain_tag = $getExplain($question['explain']);
	} else {
		$result_tag  = sprintf('<p>%s<span class="result-wrong">%s</span></p>', $this->Html->image('wrong.png', ['width' => '60', 'height' => '60']), __('不正解'));

		// 不正解時の表示
		switch ($wrong_mode) {
			case 0: // 正解と解説を表示しない
				break;
			case 1: // 正解と解説を表示する
				$correct_tag = sprintf('<p class="correct-text bg-success">%s : %s</p>', __('正解'), $correct_label);
				$explain_tag = $getExplain($question['explain']);
				break;
			case 2: // 解説のみ表示する
				$explain_tag = $getExplain($question['explain']);
				break;
		}
	}
}
?>
<div class="panel panel-info question question-<?= $question_index; ?>">
	<div class="panel-heading"><?= __('問') . $question_index; ?></div>
	<div class="panel-body">
	<!--問題タイトル-->
	<h2><?= h($title) ?></h2>
		<div class="question-text bg-warning">
			<!--問題文-->
			<?= $body ?>
		</div>

		<div class="radio-group">
			<!--選択肢-->
			<?= $option_tag; ?>
		</div>
		<!--正誤画像-->
		<?= $result_tag ?>
		<!--正解-->
		<?= $correct_tag ?>
		<!--解説文-->
		<?= $explain_tag ?>
	</div>
</div>
