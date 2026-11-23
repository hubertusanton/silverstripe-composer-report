<%t Webium\ComposerReport\Email.NEW_ADVISORIES_INTRO "New security advisories were found for {project}." project=$ProjectTitle %>

<% loop $Advisories %>- $Package: $Id, $Title. Affected: $AffectedVersions<% if $Severity %>, severity: $Severity<% end_if %><% if $Link %>
  $Link<% end_if %>
<% end_loop %>
<%t Webium\ComposerReport\Email.OPEN_IN_CMS "Open the project in the Composer monitor" %>: $CmsLink
