var _studySec		= 0;														// 学習時間
var _timerID		= null;														// 制限時間タイマーID
var _lblStudySec	= null;														// 制限時間表示ラベル
var TEST_STATE_TTL_SEC	= 7 * 24 * 60 * 60;										// 退避した回答を復元できる期間（秒）
var TEST_STATE_COOKIE_PREFIX	= 'IrohaTestState_';							// 出題リストを退避する Cookie 名の接頭辞（サーバ側と揃える）
var TEST_STATE_COOKIE_MAX_LENGTH	= 3500;										// Cookie へ退避できる出題リストの最大長

$(document).ready(function()
{
	// テスト結果表示モード以外の場合、制限時間を表示
	if(!IS_RECORD)
	{
		_lblStudySec = $("#lblStudySec");
		_lblStudySec.show();
		initTestState();
		setStudySec();
		_timerID = setInterval(setStudySec, 1000);
	}
	else if(typeof IS_ADMIN_RECORD === 'undefined' || !IS_ADMIN_RECORD)
	{
		// 本人のテスト結果表示のため、退避した受験中データは破棄する
		// （管理者が他人の履歴を表示する場合は本人のデータを消さない）
		clearTestState();
	}
});

// 学習時間の更新
function setStudySec()
{
	if(TIMELIMIT_SEC > 0)
	{
		if( _studySec > TIMELIMIT_SEC )
		{
			clearInterval(_timerID);
			
			if(typeof MSG_TIMELIMIT == 'undefined')
			{
				alert("制限時間を過ぎましたので自動採点を行います。");
			}
			else
			{
				alert(MSG_TIMELIMIT);
			}
			
			$("form").submit();
			return;
		}
		
		var restSec = TIMELIMIT_SEC - _studySec;
		var rest = CommonUtil.getHHMMSSbySec(restSec);
		
		if(typeof MSG_REST_TIME == 'undefined')
		{
			_lblStudySec.text('残り時間 : ' + rest);
		}
		else
		{
			_lblStudySec.text(MSG_REST_TIME + ' : ' + rest);
		}
		
		if(restSec < 60)
		{
			if(_lblStudySec.hasClass('btn-info'))
				_lblStudySec.removeClass('btn-info').addClass('btn-danger');
		}
	}
	else
	{
		var passed = CommonUtil.getHHMMSSbySec(_studySec);
		
		if(typeof MSG_TIME == 'undefined')
		{
			_lblStudySec.text('経過: ' + passed);
		}
		else
		{
			_lblStudySec.text(MSG_TIME + ' : ' + passed);
		}
	}
	
	$("#contents-question-study-sec").val(_studySec);
	_studySec++;
}

// 採点
function sendData()
{
	// 重複送信防止の為、ボタンを無効化
	$('.btn').prop('disabled', true);
	
	$('form').submit();
	return;
}

// ---------------------------------------------------------------------------
// 受験中データの退避・復元
//
// セッションが切れると採点結果が失われ、ログイン画面へ飛ばされてしまう。
// その対策として、回答を localStorage へ、出題内容（問題ID・出題順）を
// localStorage と Cookie へ退避し、テスト画面を開いた最初のリクエストで
// サーバが同じ出題内容を返せるようにする。
// ---------------------------------------------------------------------------

// 練習モードか（正式受験と退避データを分離する）
function isPracticeMode()
{
	return (typeof TEST_MODE !== 'undefined' && TEST_MODE === 'practice');
}

// 受験中データの保存キー（モード・ユーザ・コンテンツ単位）
function getTestStateKey()
{
	var userId		= (typeof USER_ID !== 'undefined') ? USER_ID : '0';
	var contentId	= (typeof CONTENT_ID !== 'undefined') ? CONTENT_ID : '0';
	var prefix		= isPracticeMode() ? 'iroha.practice.' : 'iroha.test.';

	return prefix + userId + '.' + contentId;
}

// 出題リストを Cookie へ退避するか
//
// 練習は記録対象ではなく、セッション切れ時は設定画面からやり直すため、
// 正式受験のような Cookie 復元は行わない（正式受験の Cookie も壊さない）。
function useTestStateCookie()
{
	return !isPracticeMode();
}

// 出題リストを退避する Cookie 名（コンテンツ単位）
function getTestStateCookieName()
{
	var contentId	= (typeof CONTENT_ID !== 'undefined') ? CONTENT_ID : '0';

	return TEST_STATE_COOKIE_PREFIX + contentId;
}

// 出題リストを Cookie へ退避する
//
// セッションが切れるとサーバ側の出題リストも失われるため、以前は退避した出題で
// 開き直す再読込が必要だった。Cookie へも退避しておくと、テスト画面を開いた
// 最初のリクエストでサーバが同じ出題内容を返せるため、再読込（セッション切れと
// 重なりやすい 2 回目のリクエスト）を不要にできる。
//
// 形式は `<ユーザID>-<問題ID>-<問題ID>-...`。カンマは Cookie 値に使えない
// 文字（RFC 6265 の cookie-octet 外）のため、区切りにはハイフンを使用する。
function saveTestStateCookie(state)
{
	if(!useTestStateCookie())
	{
		return;
	}

	try
	{
		var userId	= (typeof USER_ID !== 'undefined') ? USER_ID : '0';
		var value	= userId + '-' + state.question_id_list.join('-');

		// 1 Cookie あたりの上限を超える場合は退避しない（一致する回答のみ復元する）
		if(value.length > TEST_STATE_COOKIE_MAX_LENGTH)
		{
			return;
		}

		var secure = (window.location.protocol === 'https:') ? '; secure' : '';

		document.cookie = getTestStateCookieName() + '=' + value
			+ '; path=/; max-age=' + TEST_STATE_TTL_SEC + '; samesite=lax' + secure;
	}
	catch(e)
	{
		// Cookie を利用できない環境では保存しない
	}
}

// 出題リストを退避した Cookie を削除する
function clearTestStateCookie()
{
	if(!useTestStateCookie())
	{
		return;
	}

	try
	{
		document.cookie = getTestStateCookieName() + '=; path=/; max-age=0; samesite=lax';
	}
	catch(e)
	{
		// 何もしない
	}
}

// 表示中の出題リスト（問題ID・出題順）を取得する
function getRenderedQuestionIdList()
{
	var value = $('input[name="question_id_list"]').val();

	// サーバが出題順を保証している hidden 項目を優先する
	if(value)
	{
		return value.split(',').map(function(n){ return parseInt(n, 10); });
	}

	var ids = [];

	// hidden 項目が無い場合（全問出題など）は DOM の出現順から作成する
	$('input[name^="answer_"]').each(function()
	{
		var matched = /^answer_(\d+)/.exec($(this).attr('name'));

		if(matched)
		{
			var id = parseInt(matched[1], 10);

			if(ids.indexOf(id) === -1)
			{
				ids.push(id);
			}
		}
	});

	return ids;
}

// 回答を {input名: 値 または 値の配列} の形式で収集する
function collectAnswers()
{
	var answers = {};

	$('input[name^="answer_"]').each(function()
	{
		var $input	= $(this);
		var name	= $input.attr('name');

		if($input.attr('type') === 'checkbox')
		{
			if($input.is(':checked'))
			{
				if(!answers[name])
				{
					answers[name] = [];
				}

				answers[name].push($input.val());
			}
		}
		else if($input.is(':checked'))
		{
			answers[name] = $input.val();
		}
	});

	return answers;
}

// 受験中データを localStorage へ保存する
function saveTestState()
{
	if(IS_RECORD)
	{
		return;
	}

	var state = {
		question_id_list	: getRenderedQuestionIdList(),
		answers				: collectAnswers(),
		study_sec			: parseInt($('#contents-question-study-sec').val() || _studySec, 10) || 0,
		saved_at			: Math.floor(Date.now() / 1000)
	};

	try
	{
		localStorage.setItem(getTestStateKey(), JSON.stringify(state));
	}
	catch(e)
	{
		// localStorage を利用できない環境（プライベートモード等）では保存しない
	}

	// localStorage が使えない環境でも出題内容だけは復元できるようにする
	saveTestStateCookie(state);
}

// 受験中データを読み込む（未保存・期限切れ・破損時は null）
function loadTestState()
{
	try
	{
		var raw = localStorage.getItem(getTestStateKey());

		if(!raw)
		{
			return null;
		}

		var state = JSON.parse(raw);

		if(!state || !Array.isArray(state.question_id_list) || state.question_id_list.length === 0)
		{
			return null;
		}

		if(state.saved_at && (Math.floor(Date.now() / 1000) - state.saved_at) > TEST_STATE_TTL_SEC)
		{
			clearTestState();
			return null;
		}

		return state;
	}
	catch(e)
	{
		return null;
	}
}

// 受験中データを削除する
function clearTestState()
{
	try
	{
		localStorage.removeItem(getTestStateKey());
	}
	catch(e)
	{
		// 何もしない
	}

	clearTestStateCookie();
}

// 退避した回答をフォームへ復元する
//
// 出題内容が変わっている場合は、表示中の設問と一致する回答だけを復元する
// （一致しない設問の回答は、送信時にその設問が無いため採点対象にならない）。
//
// @return int 復元した回答の件数
function restoreAnswers(state)
{
	var restored = 0;

	$.each(state.answers || {}, function(name, value)
	{
		var $inputs = $('form').find('input[name="' + name + '"]');

		if($inputs.length === 0)
		{
			return;
		}

		if($inputs.attr('type') === 'checkbox')
		{
			var selected = Array.isArray(value) ? value : [value];

			$inputs.each(function()
			{
				$(this).prop('checked', selected.indexOf($(this).val()) !== -1);
			});

			restored++;
		}
		else
		{
			var $matched = $inputs.filter('[value="' + value + '"]');

			if($matched.length > 0)
			{
				$matched.prop('checked', true);
				restored++;
			}
		}
	});

	if(state.study_sec)
	{
		_studySec = parseInt(state.study_sec, 10) || 0;
		$("#contents-question-study-sec").val(_studySec);
	}

	return restored;
}

// 回答を復元したことを通知する
//
// @param bool complete 退避した回答がすべて復元できたか
function showAnswersRestoredNotice(complete)
{
	var message;

	if(complete)
	{
		message = (typeof MSG_RESTORED !== 'undefined') ? MSG_RESTORED : '前回の回答を復元しました';
	}
	else
	{
		message = (typeof MSG_RESTORED_PARTIAL !== 'undefined')
			? MSG_RESTORED_PARTIAL
			: '前回の回答を復元しました（出題内容が変更されたため、一部の回答は復元されていません）';
	}

	$('.contents-questions-index').first().prepend(
		$('<div class="alert alert-info test-state-restored"></div>').text(message)
	);
}

// 受験中データの復元と自動保存を開始する
function initTestState()
{
	var state = loadTestState();

	if(state)
	{
		// 復元のための再読込は行わない。再読込はセッション切れと重なると
		// ログイン画面へ飛ばされる原因になるため、出題内容は Cookie へ退避し、
		// 最初のリクエストから同じ出題が返るようにしている。
		var restored	= restoreAnswers(state);
		var savedCount	= 0;

		$.each(state.answers || {}, function(){ savedCount++; });

		if(savedCount > 0)
		{
			// 退避した回答がすべて復元できた場合のみ「すべて復元」として通知する
			// （全問出題のコンテンツでは出題順のみが変わり、回答はすべて復元される）
			showAnswersRestoredNotice(restored >= savedCount);
		}
	}

	var $form = $('form');

	$form.on('change', 'input[name^="answer_"]', function(){ saveTestState(); });
	$form.on('submit', function(){ saveTestState(); });
	$(window).on('pagehide', function(){ saveTestState(); });
	setInterval(saveTestState, 10000);
}
