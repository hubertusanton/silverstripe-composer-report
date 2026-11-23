<%t Webium\ComposerReport\Email.AUDIT_FAILING_INTRO "{project} sends reports, but composer audit has not succeeded for more than {days} days." project=$ProjectTitle days=$StaleAfterDays %>

<%t Webium\ComposerReport\Email.LAST_AUDIT "Last successful audit" %>: $LastAudit

<%t Webium\ComposerReport\Email.AUDIT_FAILING_HINT "The CMS shows the errors of the latest report, such as a disabled proc_open or no access to Packagist." %>

<%t Webium\ComposerReport\Email.OPEN_IN_CMS "Open the project in the Composer monitor" %>: $CmsLink
