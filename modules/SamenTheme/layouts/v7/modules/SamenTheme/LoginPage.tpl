{* SAMENTHEME:LOGIN-TEMPLATE – SamenCRM login page. SamenTheme copies this file over layouts/v7/modules/Users/Login.tpl
   (Vtiger's own page is kept as Login.tpl.vtiger-stock and put back when the module is disabled/uninstalled).
   Rendered by Users_Login_View between Vtiger's Header.tpl and Footer.tpl; same form fields as the stock page. *}
{strip}
	<script>
		(function (h) { h.className += ' samen-login samen-login-ready'; h.setAttribute('dir', 'rtl'); h.setAttribute('lang', 'fa');
			document.title = 'ورود | SamenCRM – سامانه مدیریت ارتباط با مشتری'; })(document.documentElement);
	</script>
	<link rel="stylesheet" href="layouts/v7/modules/SamenTheme/resources/login.css?v=__SAMEN_V__">
	<link rel="icon" type="image/png" href="layouts/v7/modules/SamenTheme/resources/img/favicon.png">
	<span class="app-nav"></span>
	<div class="container-fluid loginPageContainer">
		<div class="loginDiv">
			<img class="img-responsive user-logo" src="layouts/v7/modules/SamenTheme/resources/img/company-logo.png" alt="SamenCRM" width="474" height="120">
			<div>
				<span class="{if !$ERROR}hide{/if} failureMessage" id="validationMessage">
					{if $ERROR eq 'login'}نام کاربری یا رمز عبور نادرست است.
					{elseif $ERROR eq 'fpError'}نام کاربری یا ایمیل نادرست است.
					{elseif $ERROR eq 'statusError'}سرور ایمیل خروجی تنظیم نشده است.
					{else}{$MESSAGE}{/if}
				</span>
				<span class="{if !$MAIL_STATUS}hide{/if} successMessage">ایمیل بازیابی رمز فرستاده شد؛ صندوق ایمیل خود را ببینید.</span>
			</div>
			<div id="loginFormDiv">
				<form class="form-horizontal" method="POST" action="index.php">
					<input type="hidden" name="module" value="Users">
					<input type="hidden" name="action" value="Login">
					<input type="hidden" name="skin" value="samen">
					<div class="group">
						<input id="username" type="text" name="username" autocomplete="username" dir="ltr" required>
						<span class="bar"></span>
						<label for="username">نام کاربری</label>
					</div>
					<div class="group">
						<input id="password" type="password" name="password" autocomplete="current-password" dir="ltr" required>
						<span class="bar"></span>
						<label for="password">رمز عبور</label>
					</div>
					<div class="group">
						<button type="submit" class="button buttonBlue">ورود</button><br>
						<a href="#" class="forgotPasswordLink">فراموشی رمز عبور؟</a>
					</div>
				</form>
			</div>
			<div id="forgotPasswordDiv" class="hide">
				<form class="form-horizontal" action="forgotPassword.php" method="POST">
					<div class="group">
						<input id="fusername" type="text" name="username" autocomplete="username" dir="ltr" required>
						<span class="bar"></span>
						<label for="fusername">نام کاربری</label>
					</div>
					<div class="group">
						<input id="email" type="email" name="emailId" autocomplete="email" dir="ltr" required>
						<span class="bar"></span>
						<label for="email">ایمیل</label>
					</div>
					<div class="group">
						<button type="submit" class="button buttonBlue forgot-submit-btn">ارسال</button><br>
						<span>اطلاعات را وارد کرده و ارسال کنید <a href="#" class="forgotPasswordLink">بازگشت</a></span>
					</div>
				</form>
			</div>
		</div>
		<div class="samen-brand">
			<img src="layouts/v7/modules/SamenTheme/resources/img/cloud-art.webp" alt="" width="480" height="398" decoding="async">
			<h1 dir="ltr">SamenCRM</h1>
			<p>سامانه مدیریت ارتباط با مشتری</p>
		</div>
	</div>
	<script>
		jQuery(function () {
			var msg = jQuery('#validationMessage'), fp = jQuery('#forgotPasswordDiv'), lf = jQuery('#loginFormDiv');
			lf.find(jQuery('#username').val() ? '#password' : '#username').focus();
			jQuery('.forgotPasswordLink').on('click', function (e) {
				e.preventDefault(); lf.toggleClass('hide'); fp.toggleClass('hide'); msg.addClass('hide');
			});
			// "filled" state for the floating labels (also after the browser autofills)
			jQuery('.loginDiv .group input').on('input change blur', function () { jQuery(this).toggleClass('used', !!this.value); }).trigger('change');
		});
	</script>
{/strip}
