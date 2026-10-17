var _studySec		= 0;														// 学習時間
var _timerID		= null;														// 制限時間タイマーID
var _lblStudySec	= null;														// 制限時間表示ラベル
var TEST_STATE_TTL_SEC	= 7 * 24 * 60 * 60;										// 退避した回答を復元できる期間（秒）

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
// その対策として、出題内容と回答を localStorage へ退避し、テスト画面を
// 開き直したときに復元する。
// ---------------------------------------------------------------------------

// 受験中データの保存キー（ユーザ・コンテンツ単位）
function getTestStateKey()
{
	var userId		= (typeof USER_ID !== 'undefined') ? USER_ID : '0';
	var contentId	= (typeof CONTENT_ID !== 'undefined') ? CONTENT_ID : '0';

	return 'iroha.test.' + userId + '.' + contentId;
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

	try
	{
		var state = {
			question_id_list	: getRenderedQuestionIdList(),
			answers				: collectAnswers(),
			study_sec			: parseInt($('#contents-question-study-sec').val() || _studySec, 10) || 0,
			saved_at			: Math.floor(Date.now() / 1000)
		};

		localStorage.setItem(getTestStateKey(), JSON.stringify(state));
	}
	catch(e)
	{
		// localStorage を利用できない環境（プライベートモード等）では保存しない
	}
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
}

// 退避した回答をフォームへ復元する
function restoreAnswers(state)
{
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
		}
		else
		{
			$inputs.filter('[value="' + value + '"]').prop('checked', true);
		}
	});

	if(state.study_sec)
	{
		_studySec = parseInt(state.study_sec, 10) || 0;
		$("#contents-question-study-sec").val(_studySec);
	}
}

// 回答を復元したことを通知する
function showAnswersRestoredNotice()
{
	var message = (typeof MSG_RESTORED !== 'undefined') ? MSG_RESTORED : '前回の回答を復元しました';

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
		var rendered = getRenderedQuestionIdList();

		if(rendered.join(',') === state.question_id_list.join(','))
		{
			// 出題内容が一致する場合は回答を復元する
			restoreAnswers(state);
			showAnswersRestoredNotice();
		}
		else if(window.location.search.indexOf('question_id_list=') === -1)
		{
			// セッション切れで出題が抽選し直された場合は、退避した出題内容で開き直す。
			// 再読込後の URL には出題リストが付くため、繰り返しにはならない。
			var href = window.location.href.split('#')[0];
			var separator = (href.indexOf('?') === -1) ? '?' : '&';

			window.location.replace(
				href + separator + 'question_id_list=' + encodeURIComponent(state.question_id_list.join(','))
			);

			return;
		}
		else
		{
			// 退避した出題内容を再現できない（設問が編集・削除された等）ため破棄する
			clearTestState();
		}
	}

	var $form = $('form');

	$form.on('change', 'input[name^="answer_"]', function(){ saveTestState(); });
	$form.on('submit', function(){ saveTestState(); });
	$(window).on('pagehide', function(){ saveTestState(); });
	setInterval(saveTestState, 10000);
}
