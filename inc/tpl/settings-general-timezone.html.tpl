
<div class="sucuriscan-panel">
    <h3 class="sucuriscan-title">{{Timezone Override}}</h3>

    <div class="inside">
        <p>{{By default the plugin shows every date and time, including the audit logs and the last logins, in the timezone set under Settings &gt; General in WordPress. Pick a fixed UTC offset here only if you want the plugin to use a different timezone than the rest of the website. Fixed offsets do not follow daylight saving time.}}</p>

        <form action="%%SUCURI.URL.Settings%%" method="post">
            <input type="hidden" name="sucuriscan_page_nonce" value="%%SUCURI.PageNonce%%" />
            <fieldset class="sucuriscan-clearfix">
                <label>{{Timezone:}}</label>
                <select name="sucuriscan_timezone" data-cy="sucuriscan_timezone_select">
                    %%%SUCURI.Timezone.Dropdown%%%
                </select>
                <button type="submit" class="button button-primary" data-cy="sucuriscan_timezone_submit">{{Submit}}</button>
                <span><em>(%%SUCURI.Timezone.Example%%)</em></span>
            </fieldset>
        </form>
    </div>
</div>
