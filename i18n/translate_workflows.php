<?php
/*+**********************************************************************************
 * Persian for Vtiger's built-in sample workflows (run by install_persian.php, idempotent).
 *  - workflow names (Settings > Workflows) and task names
 *  - subjects and bodies of the e-mail tasks (assignment notices, ticket replies, event/task reminders);
 *    merge fields ($ticket_no, $(contact_id : (Contacts) lastname) ...) are kept as they are, only the English
 *    text around them is replaced, and the body is wrapped in a right-to-left block
 *  - existing e-mails already created by those tasks ("Regarding Contact Assignment" in every contact's history)
 * Tasks are loaded and saved through VTTaskManager, so the serialized task objects stay valid.
 * Only text that is still Vtiger's English default is touched – edited workflows are left alone.
 ************************************************************************************/
require_once 'modules/com_vtiger_workflow/VTTaskManager.inc';

function samen_workflow_fa() {
	global $adb;
	$names = array(
		'UpdateInventoryProducts On Every Save' => 'به‌روزرسانی موجودی کالا در هر ذخیره',
		'Update Inventory Products On Every Save' => 'به‌روزرسانی موجودی کالا در هر ذخیره (سفارش خرید)',
		'Send Email to user when Notifyowner is True' => 'ارسال ایمیل به کاربر وقتی «اطلاع به مالک» فعال است',
		'Send Email to user when Portal User is True' => 'ارسال ایمیل به کاربر وقتی «کاربر پرتال» فعال است',
		'Send Email to users on Potential creation' => 'ارسال ایمیل به کاربران هنگام ایجاد فرصت',
		'Workflow for Contact Creation or Modification' => 'گردش‌کار ایجاد یا ویرایش مخاطب',
		'Ticket Creation From Portal : Send Email to Record Owner and Contact' => 'ایجاد تیکت از پرتال: ارسال ایمیل به مالک رکورد و مخاطب',
		'Send Email to Contact on Ticket Update' => 'ارسال ایمیل به مخاطب هنگام به‌روزرسانی تیکت',
		'Workflow for Events when Send Notification is True' => 'گردش‌کار رویدادها وقتی «ارسال اعلان» فعال است',
		'Workflow for Calendar Todos when Send Notification is True' => 'گردش‌کار وظایف وقتی «ارسال اعلان» فعال است',
		'Calculate or Update forecast amount' => 'محاسبه یا به‌روزرسانی مبلغ پیش‌بینی',
		'Comment Added From CRM : Send Email to Organization' => 'افزودن نظر از CRM: ارسال ایمیل به سازمان',
		'Comment Added From Portal : Send Email to Record Owner' => 'افزودن نظر از پرتال: ارسال ایمیل به مالک رکورد',
		'Comment Added From CRM : Send Email to Contact, where Contact is not a Portal User' => 'افزودن نظر از CRM: ارسال ایمیل به مخاطبی که کاربر پرتال نیست',
		'Comment Added From CRM : Send Email to Contact, where Contact is Portal User' => 'افزودن نظر از CRM: ارسال ایمیل به مخاطبی که کاربر پرتال است',
		'Send Email to Record Owner on Ticket Update' => 'ارسال ایمیل به مالک رکورد هنگام به‌روزرسانی تیکت',
		'Ticket Creation From CRM : Send Email to Record Owner' => 'ایجاد تیکت از CRM: ارسال ایمیل به مالک رکورد',
		'Send Email to Organization on Ticket Update' => 'ارسال ایمیل به سازمان هنگام به‌روزرسانی تیکت',
		'Ticket Creation From CRM : Send Email to Organization' => 'ایجاد تیکت از CRM: ارسال ایمیل به سازمان',
		'Ticket Creation From CRM : Send Email to Contact' => 'ایجاد تیکت از CRM: ارسال ایمیل به مخاطب',
		// task names
		'An account has been created' => 'اطلاع ایجاد سازمان', 'An contact has been created' => 'اطلاع ایجاد مخاطب',
		'An Potential has been created' => 'اطلاع ایجاد فرصت', 'Email Customer Portal Login Details' => 'ارسال اطلاعات ورود پرتال مشتری',
		'Notify Related Contact when Ticket is created from Portal' => 'اطلاع به مخاطب مرتبط هنگام ایجاد تیکت از پرتال',
		'Notify Record Owner when Ticket is created from Portal' => 'اطلاع به مالک رکورد هنگام ایجاد تیکت از پرتال',
		'update forecast amount' => 'به‌روزرسانی مبلغ پیش‌بینی', 'Send Notification Email to Record Owner' => 'ارسال ایمیل اعلان به مالک رکورد',
		'Update Inventory Products' => 'به‌روزرسانی موجودی کالا',
	);
	// e-mail subjects and bodies: phrases, longest first
	$text = array(
		'An Account has been assigned to you on vtigerCRM' => 'یک سازمان در SamenCRM به شما واگذار شد',
		'An Contact has been assigned to you on vtigerCRM' => 'یک مخاطب در SamenCRM به شما واگذار شد',
		'An Potential has been assigned to you on vtigerCRM' => 'یک فرصت در SamenCRM به شما واگذار شد',
		'And <b>CustomerPortal Login Details</b> is sent to the EmailID :-' => 'و <b>اطلاعات ورود به پرتال مشتری</b> به این ایمیل فرستاده شد: ',
		'Customer has provided the following additional information to your reply:' => 'مشتری اطلاعات زیر را به پاسخ شما افزوده است:',
		'Kindly respond to above ticket at the earliest.' => 'لطفاً هرچه زودتر به این تیکت پاسخ دهید.',
		'You can use the following link to view the replies made:' => 'برای دیدن پاسخ‌ها از پیوند زیر استفاده کنید:',
		'in the "Customer Portal" at VTiger.' => 'در «پرتال مشتری» ثبت شده است.',
		'The Ticket is replied the details are :' => 'به تیکت پاسخ داده شد. جزئیات:',
		'Respond to Ticket ID##' => 'پاسخ به تیکت شماره ##', '## in Customer Portal - URGENT' => '## در پرتال مشتری - فوری',
		'Activity Notification Details:' => 'جزئیات اعلان رویداد:', 'Task Notification Details:' => 'جزئیات اعلان وظیفه:',
		'Details of account are :' => 'مشخصات سازمان:', 'Details of Contact are :' => 'مشخصات مخاطب:', 'Details of Potential are :' => 'مشخصات فرصت:',
		'Regarding Account Creation' => 'درباره ایجاد سازمان', 'Regarding Contact Creation' => 'درباره ایجاد مخاطب',
		'Regarding Potential Assignment' => 'درباره واگذاری فرصت', 'Regarding Contact Assignment' => 'درباره واگذاری مخاطب',
		'There is a reply to' => 'پاسخی برای', 'Support Administrator' => 'مدیر پشتیبانی', 'Thank You<br>Admin' => 'با سپاس<br>مدیر سامانه',
		'The comments are :' => 'نظرات:', 'Ticket Details' => 'جزئیات تیکت', '[From Portal]' => '[از پرتال]',
		'Expected Close Date:' => 'تاریخ بسته‌شدن مورد انتظار: ', 'Potential Name:' => 'نام فرصت: ', 'Potential No:' => 'شماره فرصت: ',
		'AccountName:' => 'نام سازمان: ', 'AccountType:' => 'نوع سازمان: ', 'AccountId:' => 'شناسه سازمان: ', 'Contact Id:' => 'شناسه مخاطب: ',
		'LastName:' => 'نام خانوادگی: ', 'FirstName:' => 'نام: ', 'Lead Source:' => 'منبع سرنخ: ', 'Department:' => 'دپارتمان: ',
		'Industry:' => 'صنعت: ', 'Rating:' => 'رتبه: ', 'Amount:' => 'مبلغ: ', 'Type:' => 'نوع: ', 'Description:' => 'توضیحات: ',
		'Regards' => 'با احترام', 'Thanks' => 'با سپاس', 'Dear ' => 'سلام ',
	);
	// "Label   :" (any spacing) at the start of a line / after a tag
	$labels = array(
		'Start date and time' => 'تاریخ و زمان شروع', 'End date and time' => 'تاریخ و زمان پایان', 'Contacts List' => 'فهرست مخاطبین',
		'Related To' => 'مرتبط با', 'Ticket Number' => 'شماره تیکت', 'Ticket Title' => 'عنوان تیکت', 'Ticket No' => 'شماره تیکت',
		'Ticket ID' => 'شناسه تیکت', 'Ticket Id' => 'شناسه تیکت', 'Description' => 'توضیحات', 'Location' => 'مکان', 'Priority' => 'اولویت',
		'Severity' => 'شدت', 'Category' => 'دسته', 'Solution' => 'راه‌حل', 'Subject' => 'موضوع', 'Status' => 'وضعیت', 'Event' => 'رویداد', 'Task' => 'وظیفه',
	);
	uksort($text, function ($a, $b) { return strlen($b) - strlen($a); });
	$tr = function ($s) use ($text, $labels) {
		$s = strtr($s, $text);
		foreach ($labels as $en => $fa) { $s = preg_replace('/(^|>|\[|\s)' . preg_quote($en, '/') . '\s*:/u', '$1' . $fa . ':', $s); }
		return $s;
	};

	$n = array('workflows' => 0, 'tasks' => 0, 'emails' => 0);
	foreach ($names as $en => $fa) {
		$hit = $adb->pquery('SELECT COUNT(*) c FROM com_vtiger_workflows WHERE TRIM(summary)=?', array($en));
		$n['workflows'] += (int) $adb->query_result($hit, 0, 'c');
		$adb->pquery('UPDATE com_vtiger_workflows SET summary=? WHERE TRIM(summary)=?', array($fa, $en));
		// Settings > Workflows lists "workflowname" (Vtiger 7+), which the installer filled with the same English text
		$adb->pquery('UPDATE com_vtiger_workflows SET workflowname=? WHERE TRIM(workflowname)=?', array(mb_substr($fa, 0, 100), $en));
	}
	$tm = new VTTaskManager($adb);
	$r = $adb->pquery('SELECT task_id FROM com_vtiger_workflowtasks', array());
	while ($row = $adb->fetch_array($r)) {
		$task = $tm->retrieveTask($row['task_id']);
		if (!$task) { continue; }
		$changed = false;
		$sum = trim($task->summary);
		if (isset($names[$sum])) { $task->summary = $names[$sum]; $changed = true; }
		if (get_class($task) === 'VTEmailTask') {
			if (!preg_match('/[\x{0600}-\x{06FF}]/u', $task->subject)) { $task->subject = $tr($task->subject); $changed = true; }
			if (!preg_match('/[\x{0600}-\x{06FF}]/u', strip_tags($task->content))) {
				$task->content = '<div dir="rtl" style="text-align:right;font-family:Tahoma,Arial,sans-serif">' . $tr($task->content) . '</div>';
				$changed = true;
			}
		}
		if ($changed) { $tm->saveTask($task); $n['tasks']++; }
	}
	// e-mails those tasks already produced (subject = record label shown in the updates history)
	foreach (array('Regarding Contact Assignment', 'Regarding Contact Creation', 'Regarding Account Creation', 'Regarding Potential Assignment') as $en) {
		$fa = $text[$en];
		$adb->pquery("UPDATE vtiger_activity a JOIN vtiger_crmentity e ON e.crmid=a.activityid SET a.subject=?, e.label=? WHERE e.setype='Emails' AND a.subject=?", array($fa, $fa, $en));
	}
	$c = $adb->pquery("SELECT COUNT(*) c FROM vtiger_activity a JOIN vtiger_crmentity e ON e.crmid=a.activityid WHERE e.setype='Emails' AND a.subject LIKE 'Regarding %'", array());
	$n['emails_left_english'] = (int) $adb->query_result($c, 0, 'c');
	return $n;
}


/**
 * E-mail templates, profiles and groups of the sample data.
 * Template NAMES stay English in the database – cron/SendReminder.service, the invitation mailer and the portal
 * login mail look templates up by name ("Activity Reminder", "Invite Users", "Customer Login Details"); they are
 * translated on screen by SamenTheme (theme.js). Subjects and descriptions are translated here, and the bodies of
 * the three system templates (reminders and invitation, sent by the CRM itself) as well.
 */
function samen_templates_fa() {
	global $adb;
	$subjects = array(
		'Announcement for Release' => 'اطلاعیه انتشار', 'Invoices Pending' => 'فاکتورهای پرداخت‌نشده', 'Acceptance Proposal' => 'پذیرش پیشنهاد',
		'Goods received acknowledgement' => 'تأیید دریافت کالا', 'Accept Order' => 'پذیرش سفارش', 'Change of Address' => 'تغییر نشانی',
		'Follow Up' => 'پیگیری', 'Target Crossed!' => 'هدف محقق شد!', 'Thanks Note' => 'یادداشت سپاس',
		'Customer Portal Login Details' => 'اطلاعات ورود به پرتال مشتری', 'VtigerCRM Support Notification' => 'اطلاعیه پشتیبانی SamenCRM',
		'Reminder' => 'یادآوری', 'Activity Reminder' => 'یادآوری فعالیت', 'Invitation' => 'دعوت‌نامه',
	);
	$descriptions = array(
		'Announcement of a release' => 'اطلاعیه انتشار نسخه جدید', 'Payment Due' => 'سررسید پرداخت', 'Acceptance of Proposal' => 'پذیرش پیشنهاد',
		'Acknowledged Receipt of Goods' => 'تأیید دریافت کالا', 'Acknowledgement/Acceptance of Order' => 'تأیید و پذیرش سفارش',
		'Address Change' => 'تغییر نشانی', 'Follow Up of meeting' => 'پیگیری جلسه', 'Fantastic Sales Spree!' => 'فروش فوق‌العاده!',
		'Note of thanks' => 'یادداشت سپاس', 'Send Portal login details to customer' => 'ارسال اطلاعات ورود پرتال برای مشتری',
		'Send Notification mail to customer before a week of support end date' => 'ارسال اطلاعیه به مشتری یک هفته پیش از پایان پشتیبانی',
		'Send Notification mail to customer before a month of support end date' => 'ارسال اطلاعیه به مشتری یک ماه پیش از پایان پشتیبانی',
		'Reminder' => 'یادآوری', 'Invite Users' => 'دعوت کاربران',
	);
	$n = array('subjects' => 0, 'descriptions' => 0, 'bodies' => 0);
	foreach ($subjects as $en => $fa) { $adb->pquery('UPDATE vtiger_emailtemplates SET subject=? WHERE subject=?', array($fa, $en)); }
	foreach ($descriptions as $en => $fa) { $adb->pquery('UPDATE vtiger_emailtemplates SET description=? WHERE description=?', array($fa, $en)); }

	$phrases = array(
		'This is a reminder notification for the Activity' => 'این یادآوری فعالیت زیر است',
		'You have been invited for an activity' => 'شما به فعالیت زیر دعوت شده‌اید',
		'The details are :' => 'جزئیات:', 'Thanks & Regards,' => 'با سپاس،', 'Thanks &amp; Regards,' => 'با سپاس،',
	);
	$labels = array('Start Date & Time' => 'تاریخ و زمان شروع', 'Start Date &amp; Time' => 'تاریخ و زمان شروع',
		'End Date & Time' => 'تاریخ و زمان پایان', 'End Date &amp; Time' => 'تاریخ و زمان پایان', 'Contact List' => 'فهرست مخاطبین',
		'Related To' => 'مرتبط با', 'Due Date' => 'تاریخ سررسید', 'Description' => 'توضیحات', 'Location' => 'مکان',
		'Priority' => 'اولویت', 'Status' => 'وضعیت', 'Subject' => 'موضوع');
	$r = $adb->pquery("SELECT templateid, body FROM vtiger_emailtemplates WHERE templatename IN ('Activity Reminder','ToDo Reminder','Invite Users')", array());
	while ($row = $adb->fetch_array($r)) {
		$body = decode_html($row['body']);
		if (preg_match('/[\x{0600}-\x{06FF}]/u', strip_tags($body))) { continue; }
		$body = strtr($body, $phrases);
		foreach ($labels as $en => $fa) { $body = preg_replace('/' . preg_quote($en, '/') . '\s*:/u', $fa . ':', $body); }
		$body = '<div dir="rtl" style="text-align:right;font-family:Tahoma,Arial,sans-serif">' . $body . '</div>';
		$adb->pquery('UPDATE vtiger_emailtemplates SET body=? WHERE templateid=?', array($body, $row['templateid']));
		$n['bodies']++;
	}
	// the twelve sample templates (Vtiger's English letters, vtiger.com addresses, "Powered By Vtiger"): Persian
	// SamenCRM letters with the same merge fields. Only while the body is still the untouched English sample.
	$wrap = function ($html) { return '<div dir="rtl" style="text-align:right;font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.9">' . $html . '</div>'; };
	$dear = '<p>$contacts-firstname$ $contacts-lastname$ گرامی،</p>';
	$sign = '<p>با سپاس و احترام،<br>$contacts-smownerid$</p>';
	$bodies = array(
		'Announcement for Release' => '<p>با سلام،</p><p>خوشحالیم که نسخه جدید خدمات خود را معرفی کنیم. در این نسخه امکانات تازه‌ای برای ارتباط بهتر با شما فراهم شده است:</p>'
			. '<ul><li>[امکان اول]</li><li>[امکان دوم]</li><li>[امکان سوم]</li></ul><p>برای آشنایی بیشتر یا هر پرسشی با ما در تماس باشید.</p>' . $sign,
		'Pending Invoices' => $dear . '<p>فاکتورهای زیر هنوز پرداخت نشده‌اند:</p><table border="1" cellpadding="6" style="border-collapse:collapse"><tr><th>ردیف</th><th>شماره فاکتور</th><th>تاریخ</th><th>مبلغ</th></tr><tr><td>۱</td><td></td><td></td><td></td></tr></table>'
			. '<p>اگر موردی برای بررسی وجود دارد، خوشحال می‌شویم با شما تماس بگیریم. امیدواریم همکاری ما ادامه داشته باشد.</p>' . $sign,
		'Acceptance Proposal' => $dear . '<p>پیشنهاد شما برای پروژه [نام پروژه] بررسی شد و به‌طور کامل مورد پذیرش است. از فرصت همکاری با شما خرسندیم و برای جلسه آغاز کار با شما هماهنگ خواهیم کرد.</p>' . $sign,
		'Goods received acknowledgement' => '<p>بدین‌وسیله دریافت و تحویل کالاهای زیر تأیید می‌شود. پرداخت پس از اطمینان از سلامت و انطباق کالا انجام خواهد شد.</p><p>تاریخ: ……………… &nbsp; امضا: ………………</p>',
		'Accept Order' => $dear . '<p>سفارش شما مطابق فرم خرید دریافت شد و برای هر دو طرف قطعی است. اگر اصلاحی لازم است، لطفاً حداکثر تا ده روز پس از دریافت این پیام اطلاع دهید.</p><p>از اعتماد شما سپاسگزاریم.</p>' . $sign,
		'Address Change' => $dear . '<p>به اطلاع می‌رساند نشانی دفتر ما تغییر کرده است:</p><p>نشانی جدید: [نشانی]<br>تلفن: [شماره تلفن]</p><p>لطفاً اطلاعات تماس ما را در دفترچه نشانی خود به‌روز کنید.</p>' . $sign,
		'Follow Up' => $dear . '<p>از فرصتی که برای دیدار با شما و همکارانتان فراهم کردید سپاسگزاریم. همان‌طور که در جلسه گفتیم، خدمات ما با همان کیفیت و دقت ادامه خواهد داشت و برای هر نیازی در کنار شما هستیم.</p>' . $sign,
		'Target Crossed!' => '<p>تبریک!</p><p>فروش فصل گذشته از هدف تعیین‌شده فراتر رفت. این موفقیت حاصل تلاش همه شماست؛ از همراهی و کار گروهی همه همکاران سپاسگزاریم.</p>' . $sign,
		'Thanks Note' => $dear . '<p>از اعتماد شما به ما سپاسگزاریم. خوشحالیم که فرصت خدمت به شما را داریم و امیدواریم همکاری بلندمدتی داشته باشیم. هر زمان نیازی بود، با ما تماس بگیرید.</p>' . $sign,
		'Customer Login Details' => $dear . '<p>به‌عنوان مشتری ما، از پشتیبانی کامل تیم ما برخوردارید. پرتال مشتریان برای شما فعال شد: در آن می‌توانید درخواست یا مشکل ثبت کنید، پاسخ‌ها و درخواست‌های قبلی را ببینید و به پایگاه دانش و اسنادی که با شما به اشتراک گذاشته‌ایم دسترسی داشته باشید.</p>'
			. '<p>برای ورود به پرتال روی $URL$ کلیک کنید و از این اطلاعات استفاده کنید:</p><p dir="ltr" style="text-align:left">نام کاربری: $login_name$<br>رمز عبور: $password$</p>' . $sign,
		'Support end notification before a week' => '<p>$logo$</p><p>$contacts-lastname$ گرامی،</p><p>به اطلاع می‌رساند دوره پشتیبانی شما <b>یک هفته دیگر</b> به پایان می‌رسد. برای تمدید با ما تماس بگیرید.</p><p>با احترام،<br>تیم پشتیبانی</p>',
		'Support end notification before a month' => '<p>$logo$</p><p>$contacts-lastname$ گرامی،</p><p>به اطلاع می‌رساند دوره پشتیبانی شما <b>یک ماه دیگر</b> به پایان می‌رسد. برای تمدید با ما تماس بگیرید.</p><p>با احترام،<br>تیم پشتیبانی</p>',
	);
	$r = $adb->pquery('SELECT templateid, templatename, body FROM vtiger_emailtemplates', array());
	while ($row = $adb->fetch_array($r)) {
		$name = decode_html($row['templatename']);
		if (!isset($bodies[$name])) { continue; }
		$plain = strip_tags(decode_html($row['body']));
		if (preg_match('/[\x{0600}-\x{06FF}]/u', $plain)) { continue; }      // already Persian or written by a user
		$adb->pquery('UPDATE vtiger_emailtemplates SET body=? WHERE templateid=?', array($wrap($bodies[$name]), $row['templateid']));
		$n['bodies']++;
	}

	// sample profiles and groups (names were renamed by install_persian.php; descriptions were still English)
	$desc = array(
		array('vtiger_profile', array('Admin Profile' => 'پروفایل مدیر سیستم', 'Profile Related to Sales' => 'پروفایل کارکنان فروش',
			'Profile Related to Support' => 'پروفایل کارکنان پشتیبانی', 'Guest Profile for Test Users' => 'پروفایل مهمان برای کاربران آزمایشی')),
		array('vtiger_groups', array('Group Related to Sales' => 'گروه کارکنان فروش', 'Group Related to Marketing Activities' => 'گروه فعالیت‌های بازاریابی',
			'Group Related to providing Support to Customers' => 'گروه پشتیبانی مشتریان')),
	);
	foreach ($desc as $d) {
		foreach ($d[1] as $en => $fa) { $adb->pquery("UPDATE {$d[0]} SET description=? WHERE description=?", array($fa, $en)); }
	}
	$left = $adb->pquery("SELECT COUNT(*) c FROM vtiger_emailtemplates WHERE deleted=0 AND subject REGEXP '^[A-Za-z]'", array());
	$n['subjects_left_english'] = (int) $adb->query_result($left, 0, 'c');
	return $n;
}
