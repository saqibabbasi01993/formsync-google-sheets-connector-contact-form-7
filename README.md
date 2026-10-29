# FormSync – Google Sheets Connector for Contact Form 7

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/formsync-google-sheets-connector-contact-form-7?label=version)](https://wordpress.org/plugins/formsync-google-sheets-connector-contact-form-7/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/formsync-google-sheets-connector-contact-form-7)](https://wordpress.org/plugins/formsync-google-sheets-connector-contact-form-7/#reviews)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/formsync-google-sheets-connector-contact-form-7)](https://wordpress.org/plugins/formsync-google-sheets-connector-contact-form-7/advanced/)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/formsync-google-sheets-connector-contact-form-7)](https://wordpress.org/plugins/formsync-google-sheets-connector-contact-form-7/)
[![Requires WP](https://img.shields.io/wordpress/plugin/wp-version/formsync-google-sheets-connector-contact-form-7)](https://wordpress.org/plugins/formsync-google-sheets-connector-contact-form-7/)
[![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/formsync-google-sheets-connector-contact-form-7)](https://wordpress.org/plugins/formsync-google-sheets-connector-contact-form-7/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)

> Send Contact Form 7 submissions to Google Sheets, with a spreadsheet, worksheet and field mapping for each form.

**Download from WordPress.org:** https://wordpress.org/plugins/formsync-google-sheets-connector-contact-form-7/

## Description & Features

FormSync adds each successful Contact Form 7 submission as a new row in a Google Sheets worksheet you choose. Every form can use its own spreadsheet and worksheet.

**Features**

* A "Google Sheets" tab in the Contact Form 7 editor for each form.
* Secure sign-in with a Google service account. The key file is validated, stored encrypted and never shown again.
* Pick spreadsheets and worksheets from dropdowns, or enter a spreadsheet ID or link manually.
* Automatic header row creation. Existing headers are never overwritten or reordered.
* Optional "newest first" row order: each submission is inserted directly below the header row, so the latest entry is always on top. You can keep extra title rows (or the sheet's frozen rows) above new submissions.
* Field mapping: automatic matching by name, or manual column names, per-field on/off and column ordering.
* Optional metadata columns: submission date and time, IP address, user agent, page URL, post details, site details and logged-in user details. Nothing extra is collected unless you enable it.
* Checkbox and multi-select values are stored as "Option A, Option B".
* File fields: file name only, a link to a copy of the file, or both.
* Formula-injection protection: values starting with =, +, -, @ or a tab/line break are stored as plain text.
* A failed sync never breaks the form: visitors still see the normal success message and the failure is logged.
* Duplicate protection: the same submission is never added twice to the same sheet.
* Error Logs screen with safe diagnostic information only, plus an optional debug mode.
* Connection tests for the Google account, spreadsheet and worksheet.

**Trademarks**

FormSync is an independent plugin. It is not affiliated with, endorsed by or sponsored by Google LLC or the Contact Form 7 project. Google Sheets and Google Drive are trademarks of Google LLC. Contact Form 7 is developed by Takayuki Miyoshi. These names are used only to describe what the plugin works with.

**What is never logged**

Submitted values, passwords, file contents, the service-account private key and access tokens are never written to the logs. Logs only contain the form, spreadsheet, worksheet, error type, HTTP status, a message and field names.

## Installation

1. Install and activate Contact Form 7.
2. Upload the plugin ZIP from Plugins → Add New → Upload Plugin, then activate it.
3. Go to FormSync → Google Connection and follow the steps to create a Google Cloud project, enable the Google Sheets API and Google Drive API, create a service account and download its JSON key.
4. Upload the JSON key and select Test Connection.
5. Share your spreadsheet with the service-account email shown on the screen, with Editor access.
6. Edit a contact form, open its "Google Sheets" tab, enable the integration, choose the spreadsheet and worksheet, review the field mapping and save the form.

## FAQ (highlights)

### My spreadsheet does not appear in the list
Only spreadsheets shared with the service-account email are visible. Share the spreadsheet with that email as an Editor, then select Refresh. You can also switch to manual mode and paste the spreadsheet link.

### What happens if Google is down when someone submits a form?
The visitor still sees the normal Contact Form 7 success message. The failure is recorded under FormSync → Error Logs and shown on the Integrations screen and in the form's Google Sheets tab. Google requests for one submission are limited to 15 seconds in total, so a slow or unreachable Google never keeps visitors waiting longer than that. Developers can change this with the `skfss_submission_time_budget` filter.

### Why do some cells start with an apostrophe?
Values that start with =, +, -, @ or a tab/line break are prefixed with an apostrophe so Google Sheets (and programs that open exported files) treat them as text instead of formulas. Plain phone numbers and signed numbers such as +44 20 7946 0000 are left unchanged.

### Will the plugin change my existing header row?
No. If the worksheet already has headers, they are kept as they are. When "Automatically create missing headers" is on, only missing columns are added to the right.

### Can the newest submission appear at the top?
Yes. In the form's Google Sheets tab, set Row order to "Insert below the header row (newest first)". Each new submission is then inserted as row 2 and older rows move down. Row 1 is reserved for headers and is never changed. You can set the default for newly configured forms under FormSync → Settings; forms that are already connected keep their current order until you change it.

If you have title or note rows under the header, set "Rows to keep at the top" in the same tab. For example, 3 keeps rows 1 to 3 in place and inserts each new submission in row 4. Choose "Same as the sheet's frozen rows" to follow whatever you freeze in Google Sheets (View → Freeze). Row 1 always holds the column headers used to match form fields.

The row is inserted and filled in a single Google Sheets request, so simultaneous submissions cannot overwrite each other. The new row takes its formatting from the data row below it, not from the header row. Because inserting shifts every row down, formulas elsewhere that point at fixed data rows (for example =B2) will follow the moved data; use whole-column ranges such as B2:B if you summarize the sheet.



See the full FAQ on [WordPress.org](https://wordpress.org/plugins/formsync-google-sheets-connector-contact-form-7/#faq).

## Changelog (recent)

### 1.2.2
* Renamed to "FormSync - Google Sheets Connector for Contact Form 7". Settings, connections and integrations from the earlier "Sheets Sync for Contact Form 7" and "Form2Sheet" builds are kept.
* Admin notices now appear only on the plugin's screens and Contact Form 7 screens.

### 1.2.1
* Fix: with "Add to the bottom", a new submission could be placed in row 1 or at the top of the sheet when the header row was empty, rows were hidden or moved, or the sheet had blank rows. Rows are now always added after the last row that contains data.

### 1.2.0
* New: "Rows to keep at the top" for newest-first mode. Keep title or note rows under the header in place, or follow the sheet's frozen rows.
* Existing forms keep only the header row at the top, as before.

### 1.1.0
* New: optional "newest first" row order. Submissions can be inserted directly below the header row so the latest entry is always on top. Available per form, with a site-wide default for newly configured forms.
* Existing forms keep adding rows to the bottom until you change the setting.



Full changelog: [readme.txt](readme.txt).

## Author

Developed by **[SK Freelancers](https://skfreelancers.com)** — [WordPress.org profile](https://profiles.wordpress.org/saqibabbasi/).

## License

GPLv2 or later. See [LICENSE](LICENSE).
