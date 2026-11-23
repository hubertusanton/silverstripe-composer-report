<p><%t Webium\ComposerReport\Email.STALE_INTRO "The Composer monitor has not received a report from {project} for more than {days} days." project=$ProjectTitle days=$StaleAfterDays %></p>
<p><%t Webium\ComposerReport\Email.LAST_REPORT "Last report" %>: $LastReport</p>
<p><%t Webium\ComposerReport\Email.STALE_HINT "Check the cron job of the site and the output of sake tasks:composer-report." %></p>
<p><a href="$CmsLink.ATT"><%t Webium\ComposerReport\Email.OPEN_IN_CMS "Open the project in the Composer monitor" %></a></p>
