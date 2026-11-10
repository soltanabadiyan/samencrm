/* Vtiger only wires the page (app menu, search, quick create...) when a controller class named
   <Module>_<view>_Js exists – application.js getModuleSpecificViewClass(). */
if (window.Vtiger_Index_Js && typeof window.SamenTheme_Settings_Js === 'undefined') {
	Vtiger_Index_Js('SamenTheme_Settings_Js', {}, {});
}

/* SamenTheme / PersianCalendar settings pages: buttons with data-samen-action -> <module> Ajax action. */
jQuery(function ($) {
	$(document).on('click', '[data-samen-action]', function () {
		var btn = $(this), page = btn.closest('[data-module]'), module = page.data('module') || 'SamenTheme';
		var confirmText = btn.data('confirm');
		var run = function () {
			btn.prop('disabled', true);
			app.helper.showProgress();
			app.request.post({ data: { module: module, action: 'Ajax', mode: btn.data('samen-action') } }).then(function (err, data) {
				app.helper.hideProgress();
				btn.prop('disabled', false);
				if (err) { app.helper.showErrorNotification({ message: (err && err.message) || app.vtranslate('JS_ERROR') }); return; }
				app.helper.showSuccessNotification({ message: btn.data('done') || app.vtranslate('JS_SAVED_SUCCESSFULLY') || 'OK' });
				setTimeout(function () { window.location.reload(); }, 900);
			});
		};
		if (confirmText) { app.helper.showConfirmationBox({ message: confirmText }).then(run); } else { run(); }
	});
});
