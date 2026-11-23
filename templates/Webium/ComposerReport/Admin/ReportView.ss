<div class="composer-report">
    <p><%t Webium\ComposerReport\Admin\ReportView.RECEIVED "Received" %>: $ReceivedAt</p>

    <% if $Errors %>
        <h3><%t Webium\ComposerReport\Admin\ReportView.ERRORS "Errors" %></h3>
        <ul class="composer-report__errors">
            <% loop $Errors %><li>$Message</li><% end_loop %>
        </ul>
    <% end_if %>

    <h3><%t Webium\ComposerReport\Admin\ReportView.ADVISORIES "Security advisories" %></h3>
    <% if $AuditAvailable %>
        <% if $Advisories %>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><%t Webium\ComposerReport\Admin\ReportView.PACKAGE "Package" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.ID "CVE / ID" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.TITLE "Title" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.LINK "Link" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.AFFECTED "Affected versions" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.SEVERITY "Severity" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.REPORTED "Reported" %></th>
                    </tr>
                </thead>
                <tbody>
                    <% loop $Advisories %>
                        <tr>
                            <td>$Package</td>
                            <td>$Id</td>
                            <td>$Title</td>
                            <td><% if $Link %><a href="$Link.ATT" target="_blank" rel="noopener noreferrer">$Link</a><% end_if %></td>
                            <td>$AffectedVersions</td>
                            <td>$Severity</td>
                            <td>$ReportedAt</td>
                        </tr>
                    <% end_loop %>
                </tbody>
            </table>
        <% else %>
            <p><%t Webium\ComposerReport\Admin\ReportView.NO_ADVISORIES "No known advisories." %></p>
        <% end_if %>
    <% else %>
        <p><%t Webium\ComposerReport\Admin\ReportView.AUDIT_UNAVAILABLE "The audit failed for this report, see the errors." %></p>
    <% end_if %>

    <h3><%t Webium\ComposerReport\Admin\ReportView.OUTDATED "Outdated packages" %></h3>
    <% if $OutdatedAvailable %>
        <% if $Outdated %>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><%t Webium\ComposerReport\Admin\ReportView.PACKAGE "Package" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.VERSIONS "Current → latest" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.LEVEL "Update" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.DIRECT "Direct" %></th>
                        <th><%t Webium\ComposerReport\Admin\ReportView.ABANDONED "Abandoned" %></th>
                    </tr>
                </thead>
                <tbody>
                    <% loop $Outdated %>
                        <tr>
                            <td>$Name</td>
                            <td>$Version → $Latest</td>
                            <td>$Level</td>
                            <td>$Direct</td>
                            <td>$Abandoned</td>
                        </tr>
                    <% end_loop %>
                </tbody>
            </table>
        <% else %>
            <p><%t Webium\ComposerReport\Admin\ReportView.UP_TO_DATE "Everything is up to date." %></p>
        <% end_if %>
    <% else %>
        <p><%t Webium\ComposerReport\Admin\ReportView.OUTDATED_UNAVAILABLE "composer outdated failed for this report, see the errors." %></p>
    <% end_if %>

    <h3><%t Webium\ComposerReport\Admin\ReportView.ENVIRONMENT "Environment" %></h3>
    <p>PHP $PhpVersion, Composer $ComposerVersion, Silverstripe $FrameworkVersion, host $Hostname</p>
</div>
