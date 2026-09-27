$(document).ready(function()
{
	// 一定時間経過後、メッセージを閉じる
	setTimeout(function() {
		$('#flashMessage').fadeOut("slow");
	}, 1500);
});


function CommonUtility() {}

CommonUtility.prototype.getHHMMSSbySec = function (sec)
{
	var date = new Date('2000/1/1');
	
	date.setSeconds(sec);
	
	var h = date.getHours();
	var m = date.getMinutes();
	var s = date.getSeconds();
	
	if (h < 10)
		h = '0' + h;
	
	if (m < 10)
		m = '0' + m;
	
	if (s < 10)
		s = '0' + s;
	
	var hms = h + ':' + m + ':' + s;
	
	return hms;
}


var CommonUtil = new CommonUtility();

// =========================================================================
// アクセシビリティ: Bootstrap 3 モーダル フォーカストラップ
// =========================================================================
// Bootstrap 3 には role="dialog" / aria-modal / フォーカストラップがないため、
// グローバルなイベント委譲で全モーダルに対応する。
// - 表示時に呼び出し元のフォーカスを保存し、モーダル内へフォーカスを移動
// - Tab キーでフォーカスをモーダル内に閉じ込める（サイクル）
// - 非表示時に呼び出し元へフォーカスを戻す
// - iframe を含むモーダル（アップロードダイアログ等）はフォーカストラップ対象外
//   （iframe 内のフォーカス管理は別途 iframe 側で処理）
// =========================================================================
$(document).ready(function () {
	var _previousFocus = null;

	// フォーカス可能な要素のセレクタ
	var FOCUSABLE = 'a[href], area[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])';

	// モーダル表示時（アニメーション開始前）に呼び出し元のフォーカスを保存する。
	// Bootstrap 3 は show フェーズで enforceFocus によりモーダル自身へフォーカスを
	// 移すため、shown まで待つと document.activeElement が既にモーダル要素になり、
	// 閉じたときに呼び出し元へ戻せない。
	$(document).on('show.bs.modal', '.modal', function () {
		_previousFocus = document.activeElement;
	});

	// モーダル表示後（アニメーション完了後）、フォーカスをモーダル内へ送る
	$(document).on('shown.bs.modal', '.modal', function () {
		var $modal = $(this);
		var $focusable = $modal.find(FOCUSABLE).filter(':visible');

		if ($focusable.length) {
			$focusable.first().focus();
		}
	});

	// モーダル非表示後（アニメーション完了後）、呼び出し元へフォーカスを戻す
	$(document).on('hidden.bs.modal', '.modal', function () {
		if (_previousFocus && typeof _previousFocus.focus === 'function') {
			_previousFocus.focus();
			_previousFocus = null;
		}
	});

	// Tab キーでフォーカスをモーダル内に閉じ込める
	$(document).on('keydown', '.modal', function (e) {
		// Tab キー (keyCode 9) のみ処理
		if (e.keyCode !== 9) {
			return;
		}

		var $modal = $(this);
		if (!$modal.is(':visible')) {
			return;
		}

		// iframe を含むモーダルはスキップ（iframe 内のフォーカスは別管理）
		if ($modal.find('iframe').length) {
			return;
		}

		var $focusable = $modal.find(FOCUSABLE).filter(':visible');
		if (!$focusable.length) {
			return;
		}

		var $first = $focusable.first();
		var $last = $focusable.last();

		if (e.shiftKey) {
			// Shift+Tab: 先頭要素で逆方向なら末尾へ
			if (document.activeElement === $first[0]) {
				e.preventDefault();
				$last.focus();
			}
		} else {
			// Tab: 末尾要素で順方向なら先頭へ
			if (document.activeElement === $last[0]) {
				e.preventDefault();
				$first.focus();
			}
		}
	});
});

