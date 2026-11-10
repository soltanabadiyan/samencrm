{*<!-- SamenAcademy – academy page; content is rendered by resources/academy.js from resources/content -->*}
{strip}
<div class="main-container clearfix">
<div class="content-area full-width" id="samenAcademy" data-user="{$ACADEMY_USER|escape:'html'}" data-lesson="{$ACADEMY_LESSON|escape:'html'}" data-admin="{if $ACADEMY_ADMIN}1{else}0{/if}">
	<button type="button" class="btn btn-default ac-navtoggle"><i class="fa fa-list"></i>&nbsp;فهرست دوره‌ها</button>
	<div class="ac-layout">
		<aside class="ac-nav" aria-label="دوره‌ها و درس‌ها"></aside>
		<main class="ac-main" tabindex="-1"><p class="ac-loading">در حال بارگذاری آکادمی…</p></main>
	</div>
</div>
</div>
{/strip}
