/*+**********************************************************************************
 * TelegramBot – client side (SamenCRM). Loaded on every page (HEADERSCRIPT link).
 *  - Contacts detail view: "Telegram" tab (history, link status, deep link, quick reply) + send modal
 *  - Contacts list view: mass action "send Telegram" for the selected records
 *  - module pages: bulk compose, campaigns, templates, settings
 ************************************************************************************/
(function (window, $) {
	'use strict';
	if (!$ || window.TelegramBot) { return; }

	var fa = ($('body').data('language') || document.documentElement.lang || '').indexOf('fa') === 0;
	var S = fa ? {
		tab: 'تلگرام', sent: 'ارسال شد', queued: 'در صف ارسال قرار گرفت', scheduled: 'زمان‌بندی شد برای ', failed: 'ارسال ناموفق: ',
		noSel: 'هیچ مخاطبی انتخاب نشده است', copied: 'کپی شد', confirmDelete: 'حذف شود؟', confirmCancel: 'این ارسال لغو شود؟',
		confirmUnlink: 'اتصال تلگرام این مخاطب حذف شود؟', saved: 'ذخیره شد', total: 'مخاطب', reachable: 'قابل ارسال در تلگرام',
		confirmSend: 'پیام برای {n} نفر ارسال شود؟', working: 'در حال پردازش…', worker: 'دریافت: {r}، ارسال: {s}، ناموفق: {f}',
		chars: 'نویسه', ok: 'اتصال برقرار است', notOk: 'اتصال برقرار نشد: ', preview: 'پیش‌نمایش', notLinkedCount: 'متصل نیستند'
	} : {
		tab: 'Telegram', sent: 'Sent', queued: 'Queued for sending', scheduled: 'Scheduled for ', failed: 'Sending failed: ',
		noSel: 'No record selected', copied: 'Copied', confirmDelete: 'Delete?', confirmCancel: 'Cancel this campaign?',
		confirmUnlink: 'Remove the Telegram link of this contact?', saved: 'Saved', total: 'contacts', reachable: 'reachable on Telegram',
		confirmSend: 'Send the message to {n} recipients?', working: 'Working…', worker: 'received: {r}, sent: {s}, failed: {f}',
		chars: 'characters', ok: 'Connection OK', notOk: 'No connection: ', preview: 'Preview', notLinkedCount: 'not linked'
	};

	function ok(msg) { app.helper.showSuccessNotification({ message: msg }); }
	function err(msg) { app.helper.showErrorNotification({ message: msg }); }
	function esc(s) { return $('<div/>').text(s == null ? '' : String(s)).html(); }

	/** POST to the Ajax action; resolves with result, rejects with the error message. */
	function post(mode, data) {
		var d = $.Deferred();
		var payload = $.extend({ module: 'TelegramBot', action: 'Ajax', mode: mode }, data || {});
		$.ajax({ url: 'index.php', type: 'POST', data: payload, dataType: 'json' }).done(function (r) {
			if (r && r.success) { d.resolve(r.result); } else { d.reject(r && r.error ? r.error.message : 'error'); }
		}).fail(function (x) { d.reject(x.statusText || 'error'); });
		return d.promise();
	}
	function postForm(form) {
		var d = $.Deferred();
		var fd = new FormData(form);
		$.ajax({ url: 'index.php', type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' }).done(function (r) {
			if (r && r.success) { d.resolve(r.result); } else { d.reject(r && r.error ? r.error.message : 'error'); }
		}).fail(function (x) { d.reject(x.statusText || 'error'); });
		return d.promise();
	}

	function insertAtCursor(ta, text) {
		ta = ta[0] || ta;
		var s = ta.selectionStart || 0, e = ta.selectionEnd || 0, v = ta.value;
		ta.value = v.substring(0, s) + text + v.substring(e);
		ta.selectionStart = ta.selectionEnd = s + text.length;
		$(ta).trigger('input').focus();
	}

	function select2(el) {
		try { if (window.vtUtils && vtUtils.showSelect2ElementView) { vtUtils.showSelect2ElementView(el); } } catch (e) { /* ignore */ }
	}

	/* ================================================================== composer */
	function bindCompose(form, opts) {
		opts = opts || {};
		var $f = $(form);
		if ($f.data('tgBound')) { return; }
		$f.data('tgBound', 1);
		var body = $f.find('.tg-body');
		body.on('input', function () { $f.find('.tg-counter').text(body.val().length + ' ' + S.chars); });
		$f.on('change', '.tg-template-pick', function () {
			var id = $(this).val(); if (!id) { return; }
			post('getTemplate', { id: id }).then(function (t) { if (t) { body.val(t.body).trigger('input'); } }, err);
			$(this).val('');
		});
		$f.on('change', '.tg-merge-pick', function () { if ($(this).val()) { insertAtCursor(body, $(this).val()); $(this).val(''); } });
		$f.on('change', 'input[name=media_source]', function () {
			var v = $f.find('input[name=media_source]:checked').val();
			$f.find('.tg-media-upload').toggleClass('hide', v !== 'upload');
			$f.find('.tg-media-document').toggleClass('hide', v !== 'document');
			$f.find('.tg-media-type').toggleClass('hide', !v);
			if (v === 'document') { searchDocs(''); }
		});
		var timer;
		function searchDocs(q) {
			post('searchDocuments', { q: q }).then(function (rows) {
				var sel = $f.find('.tg-doc-select').empty().append('<option value="">—</option>');
				$.each(rows, function (i, r) { sel.append($('<option/>').val(r.id).text(r.title + ' – ' + r.filename)); });
			}, err);
		}
		$f.on('input', '.tg-doc-search', function () { var q = $(this).val(); clearTimeout(timer); timer = setTimeout(function () { searchDocs(q); }, 300); });
		$f.on('change', 'input[name=when]', function () {
			$f.find('.tg-when-later').toggleClass('hide', $f.find('input[name=when]:checked').val() !== 'later');
		});
		if (window.vtUtils && vtUtils.registerEventForDateFields) { try { vtUtils.registerEventForDateFields($f.find('.dateField')); } catch (e) { /* ignore */ } }

		// bulk targets
		$f.on('change', '.tg-target-type', function () {
			var t = $(this).val();
			$f.find('.tg-target').addClass('hide');
			$f.find('.tg-target-' + t).removeClass('hide');
			$f.find('.tg-count-box').empty();
		});
		$f.on('change', '.tg-pl-field', function () { loadPicklist($(this).val()); });
		function loadPicklist(field) {
			var sel = $f.find('.tg-pl-values');
			if (!field) { sel.empty(); return; }
			post('picklistValues', { field: field }).then(function (vals) {
				if (sel.data('select2')) { sel.select2('destroy'); }
				sel.empty();
				$.each(vals, function (i, v) { sel.append($('<option/>').val(v.value).text(v.value + ' (' + v.count + ')')); });
				select2(sel);
			}, err);
		}
		if ($f.find('.tg-pl-field').length) {
			select2($f.find('select.select2').not('.tg-pl-values'));
			loadPicklist($f.find('.tg-pl-field').val());
		}
		function countParams() {
			var data = {};
			$.each($f.serializeArray(), function (i, kv) {
				if (kv.name === 'mode' || kv.name === 'action' || kv.name === 'module' || kv.name === 'body') { return; }
				if (/\[\]$/.test(kv.name)) { (data[kv.name] = data[kv.name] || []).push(kv.value); } else { data[kv.name] = kv.value; }
			});
			return data;
		}
		function count() {
			var box = $f.find('.tg-count-box');
			if (!box.length) { return $.Deferred().resolve(null).promise(); }
			box.html('<span class="text-muted">' + S.working + '</span>');
			return post('count', countParams()).then(function (r) {
				box.html('<div class="alert alert-info" style="padding:6px 10px;margin:0"><strong>' + r.total + '</strong> ' + S.total + ' – <strong>' + r.reachable + '</strong> ' + S.reachable +
					(r.total > r.reachable ? ' (' + (r.total - r.reachable) + ' ' + S.notLinkedCount + ')' : '') + '</div>');
				return r;
			}, function (m) { box.empty(); err(m); });
		}
		$f.on('click', '.tg-count-btn', count);
		if (opts.countOnLoad) { count(); }

		$f.on('click', '.tg-preview-btn', function () {
			post('preview', { body: body.val(), record: $f.find('input[name=record]').val() || '', botid: $f.find('[name=botid]').val() || '' }).then(function (r) {
				app.helper.showAlertBox ? app.helper.showAlertBox({ message: '<div dir="auto" style="white-space:pre-wrap;text-align:start">' + esc(r.text) + '</div>' }) : alert(r.text);
			}, err);
		});

		$f.on('click', '.tg-send-btn', function () {
			var btn = $(this);
			var single = $f.find('input[name=target_type]').val() === 'ids';
			var go = function () {
				btn.prop('disabled', true);
				app.helper.showProgress();
				postForm($f[0]).then(function (r) {
					app.helper.hideProgress(); btn.prop('disabled', false);
					if (r.single) {
						if (r.status === 'sent') { ok(S.sent); } else if (r.status === 'failed') { err(S.failed + (r.error || '')); } else { ok(r.message); }
					} else if (r.later) {
						ok(S.scheduled + r.scheduled);
					} else {
						var st = r.stats || {};
						ok(S.queued + ' – ' + S.sent + ': ' + (st.sent || 0) + ' / ' + (r.total || 0) + (st.failed ? ' – ✖ ' + st.failed : ''));
					}
					body.val('').trigger('input');
					$f.find('input[type=file]').val('');
					if (opts.onSent) { opts.onSent(r); }
				}, function (m) { app.helper.hideProgress(); btn.prop('disabled', false); err(m); });
			};
			if (single) { go(); return; }
			count().then(function (r) {
				var n = r ? r.reachable : '?';
				if (r && !r.reachable) { err(S.noSel); return; }
				app.helper.showConfirmationBox({ message: S.confirmSend.replace('{n}', n) }).then(go);
			});
		});
	}

	/* ================================================================== contact detail */
	function recordId() { return $('#recordId').val() || (app.getRecordId ? app.getRecordId() : ''); }

	function openSend(record, list) {
		var data = { module: 'TelegramBot', view: 'SendModal' };
		if (record) { data.record = record; } else { data.list = JSON.stringify(list); }
		app.helper.showProgress();
		$.ajax({ url: 'index.php', type: 'POST', data: data }).done(function (html) {
			app.helper.hideProgress();
			app.helper.showModal(html, {
				cb: function (modal) {
					var form = modal.find('.tg-send-form');
					bindCompose(form, {
						countOnLoad: !record,
						onSent: function () { app.helper.hideModal(); if ($('.tg-history').length) { loadHistory(); } }
					});
				}
			});
		}).fail(function () { app.helper.hideProgress(); err('error'); });
	}

	function loadHistory() {
		var holder = $('div.details').first();
		if (!holder.length) { return; }
		app.helper.showProgress();
		$.get('index.php', { module: 'TelegramBot', view: 'History', record: recordId() }).done(function (html) {
			app.helper.hideProgress();
			holder.html(html);
			var tab = $('.related-tabs li.tg-tab');
			tab.siblings().removeClass('active'); tab.addClass('active');
			bindHistory(holder.find('.tg-history'));
		}).fail(function () { app.helper.hideProgress(); err('error'); });
	}

	function bindHistory(c) {
		var rec = c.data('record');
		var thread = c.find('.tg-thread');
		thread.scrollTop(thread[0] ? thread[0].scrollHeight : 0);
		bindCompose(c.find('.tg-reply-form'), { onSent: loadHistory });
		c.on('click', '.tg-refresh', loadHistory);
		c.on('click', '.tg-copy', function () {
			var inp = $(this).closest('.input-group').find('input')[0];
			inp.select();
			try { navigator.clipboard.writeText(inp.value).then(function () { ok(S.copied); }); } catch (e) { document.execCommand('copy'); ok(S.copied); }
		});
		c.on('change', '.tg-optout', function () {
			post('setOptout', { record: rec, botid: $(this).closest('.tg-link-panel').data('botid'), value: this.checked ? 1 : 0 }).then(function () { ok(S.saved); loadHistory(); }, err);
		});
		c.on('click', '.tg-unlink', function () {
			var botid = $(this).closest('.tg-link-panel').data('botid');
			app.helper.showConfirmationBox({ message: S.confirmUnlink }).then(function () {
				post('unlink', { record: rec, botid: botid }).then(loadHistory, err);
			});
		});
		c.on('click', '.tg-retry', function (e) {
			e.preventDefault();
			post('retryMessage', { id: $(this).data('id') }).then(function (r) { if (r.status === 'sent') { ok(S.sent); } else { err(S.failed + r.error); } loadHistory(); }, err);
		});
	}

	function injectTab() {
		var nav = $('.related-tabs ul.nav-tabs').first();
		if (!nav.length || nav.find('.tg-tab').length) { return; }
		var li = $('<li class="tab-item tg-tab" data-label-key="Telegram"><a href="javascript:void(0)" class="textOverflowEllipsis"><span class="tab-label"><strong><i class="fa fa-telegram"></i> ' + S.tab + '</strong></span></a></li>');
		// insert after the built-in tabs (Summary / Details / Updates), before the related-module tabs
		var builtin = nav.children('li.tab-item').filter(function () { return !$(this).data('module'); }).last();
		if (builtin.length) { builtin.after(li); } else { nav.prepend(li); }
		li.on('click', function (e) { e.preventDefault(); e.stopPropagation(); loadHistory(); });
		if (/[?&]tgtab=1/.test(window.location.search)) { loadHistory(); }
	}

	/* ================================================================== list view */
	function massSend() {
		var list = window.app.controller();
		if (!list || !list.getListSelectAllParams) { return; }
		var params = list.getListSelectAllParams(true);
		if (!params || (!params.selected_ids || !params.selected_ids.length) && params.selected_ids !== 'all') {
			if (list.noRecordSelectedAlert) { list.noRecordSelectedAlert(); } else { err(S.noSel); }
			return;
		}
		params.viewname = list.getCurrentCvId ? list.getCurrentCvId() : $('[name=cvid]').val();
		openSend(null, params);
	}

	/* ================================================================== module pages */
	function bindIndex(page) {
		bindCompose(page.find('.tg-bulk-form'), { onSent: function () { setTimeout(function () { window.location.href = 'index.php?module=TelegramBot&view=Index&tab=campaigns'; }, 1200); } });
		page.on('click', '.tg-run-worker', function () {
			var b = $(this).prop('disabled', true);
			app.helper.showProgress(S.working);
			post('runWorker').then(function (r) {
				app.helper.hideProgress(); b.prop('disabled', false);
				if (r.locked) { ok(S.working); return; }
				ok(S.worker.replace('{r}', r.received).replace('{s}', r.sent).replace('{f}', r.failed));
				if (r.errors && r.errors.length) { err(r.errors.join('<br>')); }
				setTimeout(function () { window.location.reload(); }, 1500);
			}, function (m) { app.helper.hideProgress(); b.prop('disabled', false); err(m); });
		});
		page.on('click', '.tg-cancel-campaign', function () {
			var id = $(this).data('id');
			app.helper.showConfirmationBox({ message: S.confirmCancel }).then(function () { post('cancelCampaign', { id: id }).then(function () { window.location.reload(); }, err); });
		});
		page.on('click', '.tg-retry', function (e) {
			e.preventDefault();
			post('retryMessage', { id: $(this).data('id') }).then(function (r) { if (r.status === 'sent') { ok(S.sent); } else { err(S.failed + r.error); } setTimeout(function () { window.location.reload(); }, 1000); }, err);
		});
		var tf = page.find('.tg-template-form');
		tf.on('change', '.tg-merge-pick', function () { if ($(this).val()) { insertAtCursor(tf.find('.tg-body'), $(this).val()); $(this).val(''); } });
		page.on('click', '.tg-edit-template', function (e) {
			e.preventDefault();
			post('getTemplate', { id: $(this).data('id') }).then(function (t) { tf.find('[name=id]').val(t.id); tf.find('[name=title]').val(t.title); tf.find('[name=body]').val(t.body); }, err);
		});
		page.on('click', '.tg-new-template', function () { tf[0].reset(); tf.find('[name=id]').val(''); });
		page.on('click', '.tg-save-template', function () {
			post('saveTemplate', { id: tf.find('[name=id]').val(), title: tf.find('[name=title]').val(), body: tf.find('[name=body]').val() }).then(function () {
				ok(S.saved); window.location.href = 'index.php?module=TelegramBot&view=Index&tab=templates';
			}, err);
		});
		page.on('click', '.tg-delete-template', function () {
			var id = $(this).data('id');
			app.helper.showConfirmationBox({ message: S.confirmDelete }).then(function () { post('deleteTemplate', { id: id }).then(function () { window.location.reload(); }, err); });
		});
	}

	function bindSettings(page) {
		var bf = page.find('.tg-bot-form');
		page.on('click', '.tg-bot-save', function () {
			app.helper.showProgress();
			post('saveBot', { id: bf.find('[name=id]').val(), title: bf.find('[name=title]').val(), token: bf.find('[name=token]').val() }).then(function (r) {
				app.helper.hideProgress();
				if (r.error) { err(r.error); } else { ok(S.saved + (r.username ? ' – @' + r.username : '')); }
				setTimeout(function () { window.location.reload(); }, 1200);
			}, function (m) { app.helper.hideProgress(); err(m); });
		});
		function botId(el) { return $(el).closest('tr').data('id'); }
		page.on('click', '.tg-bot-edit', function () {
			bf.find('[name=id]').val(botId(this)); bf.find('[name=title]').val($(this).data('title')); bf.find('[name=token]').val('').focus();
			bf.find('.tg-bot-save span').text(S.saved.replace(/.*/, fa ? 'ذخیره' : 'Save'));
		});
		page.on('click', '.tg-bot-test', function () {
			app.helper.showProgress();
			post('testBot', { id: botId(this) }).then(function (r) { app.helper.hideProgress(); ok(S.ok + ' – @' + r.username + ' (' + r.name + ')'); }, function (m) { app.helper.hideProgress(); err(S.notOk + m); });
		});
		page.on('click', '.tg-bot-default', function () { post('setDefaultBot', { id: botId(this) }).then(function () { window.location.reload(); }, err); });
		page.on('change', '.tg-bot-active', function () { post('toggleBot', { id: botId(this), value: this.checked ? 1 : 0 }).then(function () { ok(S.saved); }, err); });
		page.on('click', '.tg-bot-delete', function () {
			var id = botId(this);
			app.helper.showConfirmationBox({ message: S.confirmDelete }).then(function () { post('deleteBot', { id: id }).then(function () { window.location.reload(); }, err); });
		});
		var sf = page.find('.tg-settings-form');
		page.on('click', '.tg-test-proxy', function () {
			var out = page.find('.tg-proxy-result').html('<span class="text-muted">' + S.working + '</span>');
			post('testProxy', { api_base: sf.find('[name=api_base]').val(), proxy: sf.find('[name=proxy]').val() }).then(function (r) {
				out.html(r.reachable ? '<span class="text-success">✔ ' + S.ok + '</span>' : '<span class="text-danger">✖ ' + S.notOk + esc(r.detail) + '</span>');
			}, function (m) { out.html('<span class="text-danger">' + esc(m) + '</span>'); });
		});
		page.on('click', '.tg-save-settings', function () {
			var data = {};
			$.each(sf.serializeArray(), function (i, kv) { data[kv.name] = kv.value; });
			post('saveSettings', data).then(function () { ok(S.saved); }, err);
		});
		page.on('click', '.tg-run-birthdays', function () {
			post('runBirthdays').then(function (r) { ok('🎂 ' + r.queued); }, err);
		});
	}

	window.TelegramBot = {
		openSend: function (record) { openSend(record || recordId()); },
		massSend: massSend,
		loadHistory: loadHistory,
		bindCompose: bindCompose
	};

	$(function () {
		var mod = window.app && app.getModuleName ? app.getModuleName() : '';
		var view = window.app && app.getViewName ? app.getViewName() : '';
		if (mod === 'Contacts' && view === 'Detail') { injectTab(); }
		var idx = $('#tgIndex'); if (idx.length) { bindIndex(idx); }
		var st = $('#tgSettings'); if (st.length) { bindSettings(st); }
	});
})(window, window.jQuery);
