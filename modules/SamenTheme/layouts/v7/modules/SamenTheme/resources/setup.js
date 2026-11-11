/* SamenTheme – first-login pages (Users/SystemSetup.tpl, Users/UserSetup.tpl): SamenCRM logo and background, Persian RTL.
   Loaded by the SAMENTHEME:SETUP hook. Vtiger prints most of SystemSetup in English without vtranslate(). */
(function (document) {
	'use strict';
	var BASE = 'layouts/v7/modules/SamenTheme/resources/';
	var FA = {
		'What would you like to use Vtiger CRM for?': 'از SamenCRM برای چه کارهایی استفاده می‌کنید؟',
		'(Select features you want to enable)': '(امکاناتی را که باید فعال شوند انتخاب کنید)',
		'Select All': 'انتخاب همه', 'View Modules': 'ماژول‌ها', 'Next': 'بعدی', 'Note:': 'توجه:',
		'This feature will be installed by default': 'این بخش همیشه نصب می‌شود',
		'These Modules will be enabled for this feature': 'این ماژول‌ها با این بخش فعال می‌شوند',
		'You can Enable/Disable modules from module manager later': 'بعداً می‌توانید ماژول‌ها را در تنظیمات › مدیریت ماژول‌ها فعال یا غیرفعال کنید',
		'Contact Management': 'مدیریت مخاطبین',
		'Unify and store your contacts alongside detailed notes, documents, emails, calendar events, and more. Additionally, configure what information each CRM user can see and update, and automate business activities such as email responses and contact information updates.':
			'مخاطبین را همراه با یادداشت‌ها، اسناد، ایمیل‌ها و رویدادهای تقویم یک‌جا نگه دارید، تعیین کنید هر کاربر چه اطلاعاتی را ببیند و ویرایش کند، و کارهایی مثل پاسخ ایمیل و به‌روزرسانی اطلاعات تماس را خودکار کنید.',
		'Sales Automation': 'خودکارسازی فروش',
		'Capture Leads from your website, or import lists of them, then develop a process for qualifying and turning them into potential sales opportunities, and another for winning those potential opportunities. Additionally, track and segment your sales funnel, individual, and team, performance areas.':
			'سرنخ‌ها را از وب‌سایت بگیرید یا فهرستشان را وارد کنید، آن‌ها را به فرصت فروش تبدیل و تا بستن قرارداد دنبال کنید، و عملکرد قیف فروش، افراد و تیم را بسنجید.',
		'Marketing': 'بازاریابی',
		'Send individual, targeted, or bulk emails to your contacts, leads, and customers, and see how they engage with each communication, with tools to analyze and improve campaign performance.':
			'برای مخاطبین، سرنخ‌ها و مشتریان ایمیل تکی، هدفمند یا گروهی بفرستید، واکنش آن‌ها را ببینید و عملکرد کمپین‌ها را تحلیل و بهتر کنید.',
		'Support': 'پشتیبانی',
		'Create and track customer requests/tasks via tickets, and even allow your customers to create and monitor their own tickets and details through a professional customer portal.':
			'درخواست‌های مشتریان را با تیکت ثبت و پیگیری کنید و به مشتریان اجازه دهید از پرتال مشتری، تیکت‌های خود را ثبت و دنبال کنند.',
		'Invoicing & Inventory Management': 'فاکتور و مدیریت موجودی',
		'Build a database of your products and services, maintain inventories, standard prices and prices books, and use these to create quotes, invoices, and sales orders.':
			'فهرست محصولات و خدمات، موجودی، قیمت‌ها و فهرست‌های قیمت را نگه دارید و با آن‌ها پیش‌فاکتور، فاکتور و سفارش فروش بسازید.',
		'Project Management': 'مدیریت پروژه',
		'Build and manage customer-associated projects, with detailed tasks that can be assigned to CRM users and placed on their calendars.':
			'پروژه‌های مرتبط با مشتریان را با وظایف جزئی بسازید و مدیریت کنید و وظایف را به کاربران بسپارید تا در تقویمشان قرار گیرد.',
		'Contacts': 'مخاطبین', 'Organizations': 'سازمان‌ها', 'Mail Manager': 'مدیریت ایمیل', 'Reports': 'گزارش‌ها',
		'Access Control': 'کنترل دسترسی', 'Workflows': 'گردش‌کارها', 'Mail Converter': 'تبدیل ایمیل', 'Web-to-lead forms': 'فرم‌های وب',
		'Opportunities': 'فرصت‌ها', 'Tickets': 'تیکت‌ها', 'Service Contracts': 'قراردادهای خدمات', 'Customer Portal': 'پرتال مشتری',
		'Quotes': 'پیش‌فاکتورها', 'Invoice': 'فاکتورها', 'Sales Order': 'سفارش‌های فروش', 'Purchase Orders': 'سفارش‌های خرید',
		'Price Books': 'فهرست‌های قیمت', 'Projects': 'پروژه‌ها', 'Tasks': 'وظایف', 'Milestones': 'نقاط عطف',
		'Products': 'محصولات', 'Services': 'خدمات', 'Vendors': 'تأمین‌کنندگان', 'Assets': 'دارایی‌ها', 'Campaigns': 'کمپین‌ها',
		'Leads': 'سرنخ‌ها', 'Documents': 'اسناد', 'Calendar': 'تقویم', 'SMS Notifier': 'پیامک', 'FAQ': 'پرسش‌های متداول', 'Faq': 'پرسش‌های متداول',
		'PBX Manager': 'تلفن (PBX)', 'Recycle Bin': 'سطل بازیافت', 'RSS': 'RSS', 'Webforms': 'فرم‌های وب', 'Email Templates': 'قالب‌های ایمیل',
		'Preferences': 'تنظیمات شخصی'
	};
	var BRAND_RE = /\bv ?tiger(\s+CRM)?\b/gi;
	function tr(root) {
		var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false), n, list = [];
		while ((n = w.nextNode())) { list.push(n); }
		list.forEach(function (t) {
			var k = t.nodeValue.replace(/ /g, ' ').trim();
			if (!k) { return; }
			if (Object.prototype.hasOwnProperty.call(FA, k)) { t.nodeValue = FA[k]; return; }
			BRAND_RE.lastIndex = 0;
			if (BRAND_RE.test(t.nodeValue)) { BRAND_RE.lastIndex = 0; t.nodeValue = t.nodeValue.replace(BRAND_RE, 'SamenCRM'); }
		});
		Array.prototype.forEach.call(root.querySelectorAll('[title], [alt], [placeholder], button[value], input[type=submit]'), function (e) {
			['title', 'alt', 'placeholder'].forEach(function (a) {
				var v = e.getAttribute(a);
				if (v) { BRAND_RE.lastIndex = 0; if (BRAND_RE.test(v)) { BRAND_RE.lastIndex = 0; e.setAttribute(a, v.replace(BRAND_RE, 'SamenCRM')); } }
			});
		});
	}
	function start() {
		var html = document.documentElement;
		html.classList.add('samen-setup');
		html.setAttribute('lang', 'fa');
		html.setAttribute('dir', 'rtl');
		document.title = 'راه‌اندازی | SamenCRM – سامانه مدیریت ارتباط با مشتری';
		Array.prototype.forEach.call(document.querySelectorAll('link[rel~="icon"], link[rel~="ICON"], link[REL]'), function (l) {
			if (/icon/i.test(l.getAttribute('rel') || '')) { l.parentNode.removeChild(l); }
		});
		var icon = document.createElement('link');
		icon.rel = 'icon'; icon.type = 'image/png'; icon.href = BASE + 'img/favicon.png';
		document.head.appendChild(icon);

		// SystemSetup: the vtiger logo at the top
		var logo = document.querySelector('.page-container .logo img');
		if (logo) { logo.src = BASE + 'img/company-logo.png'; logo.alt = 'SamenCRM'; }
		// UserSetup: the page is only a dialog over a picture of the vtiger dashboard – put our logo above it
		var modal = document.querySelector('form > .modal');
		if (modal && !document.querySelector('.samen-setup-brand')) {
			var brand = document.createElement('div');
			brand.className = 'samen-setup-brand';
			var img = document.createElement('img');
			img.src = BASE + 'img/company-logo.png'; img.alt = 'SamenCRM'; img.width = 237; img.height = 60;
			brand.appendChild(img);
			modal.parentNode.insertBefore(brand, modal);
		}
		tr(document.body);
		html.classList.add('samen-setup-ready');
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(document);
