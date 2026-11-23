<p><%t Webium\ComposerReport\Email.NEW_ADVISORIES_INTRO "New security advisories were found for {project}." project=$ProjectTitle %></p>
<table cellpadding="4" cellspacing="0" border="1">
    <thead>
        <tr>
            <th><%t Webium\ComposerReport\Email.PACKAGE "Package" %></th>
            <th><%t Webium\ComposerReport\Email.ID "CVE / ID" %></th>
            <th><%t Webium\ComposerReport\Email.TITLE "Title" %></th>
            <th><%t Webium\ComposerReport\Email.AFFECTED "Affected versions" %></th>
            <th><%t Webium\ComposerReport\Email.SEVERITY "Severity" %></th>
        </tr>
    </thead>
    <tbody>
        <% loop $Advisories %>
            <tr>
                <td>$Package</td>
                <td><% if $Link %><a href="$Link.ATT">$Id</a><% else %>$Id<% end_if %></td>
                <td>$Title</td>
                <td>$AffectedVersions</td>
                <td>$Severity</td>
            </tr>
        <% end_loop %>
    </tbody>
</table>
<p><a href="$CmsLink.ATT"><%t Webium\ComposerReport\Email.OPEN_IN_CMS "Open the project in the Composer monitor" %></a></p>
