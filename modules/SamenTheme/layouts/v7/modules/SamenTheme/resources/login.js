/* SamenTheme – login page: brand panel, Persian labels, always use the "samen" custom skin. */
(function (document) {
	'use strict';
	var BASE = 'layouts/v7/modules/SamenTheme/resources/';
	var FA = {
		'Username': 'نام کاربری', 'Password': 'رمز عبور', 'Email': 'ایمیل', 'Sign in': 'ورود',
		'forgot password?': 'فراموشی رمز عبور؟', 'Submit': 'ارسال', 'Back': 'بازگشت',
		'Please enter details and submit': 'اطلاعات را وارد کرده و ارسال کنید'
	};
	function tr(root) {
		var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false), n;
		while ((n = w.nextNode())) {
			var k = n.nodeValue.trim();
			if (FA[k]) { n.nodeValue = n.nodeValue.replace(k, FA[k]); }
		}
		// the template has both a placeholder and a floating <label>; keep only the label
		Array.prototype.forEach.call(root.querySelectorAll('.group input[placeholder]'), function (i) { i.placeholder = ''; });
	}
	function start() {
		var html = document.documentElement;
		html.classList.add('samen-login');
		html.setAttribute('lang', 'fa');
		html.setAttribute('dir', 'rtl');
		document.title = 'ورود | SamenCRM – سامانه مدیریت ارتباط با مشتری';
		var icon = document.createElement('link');
		icon.rel = 'icon'; icon.type = 'image/png'; icon.href = BASE + 'img/favicon.png';
		document.head.appendChild(icon);

		var logo = document.querySelector('.loginDiv .user-logo');
		if (logo) { logo.src = BASE + 'img/company-logo.png'; logo.alt = 'SamenCRM'; }

		// Vtiger 8 custom skin: preselect ours so every login uses it (Users/actions/Login.php stores it in the session)
		var skin = document.getElementById('skin');
		if (skin && skin.querySelector('option[value="samen"]')) { skin.value = 'samen'; }

		var container = document.querySelector('.loginPageContainer');
		if (container && !container.querySelector('.samen-brand')) {
			var brand = document.createElement('div');
			brand.className = 'samen-brand';
			var art = document.createElement('img');
			art.src = BASE + 'img/cloud-art.webp'; art.alt = ''; art.width = 480; art.height = 398; art.decoding = 'async';
			var h1 = document.createElement('h1'); h1.textContent = 'SamenCRM'; h1.setAttribute('dir', 'ltr');
			var p = document.createElement('p'); p.textContent = 'سامانه مدیریت ارتباط با مشتری';
			brand.appendChild(art); brand.appendChild(h1); brand.appendChild(p);
			container.appendChild(brand);
		}
		tr(document.body);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(document);
