Abandoned Cart Emails
=====================

Overview
--------

**Abandoned Cart Emails** is a cronjob that does the following:

* Looks in the eZOrder table for shopping carts that have never been finalized.
* Checks to see if a user has already been emailed about their abandoned cart.
  If not, uses Campaign Monitor to send an HTML email.
* Logs an entry in mail_logs to indicate that the email has been sent.

campaign_monitor.ini Parameters
--------------------------------

The following parameters have been added to campaign_monitor.ini.append.php

    [AbandonedCartEmail]

    # the age of the oldest cart to report on (in days)
    DateRangeStartDays=7

	# the age of the newest cart to report on (days)
	DateRangeEndDays=1

	# subject line of the abandoned cart email
	EmailSubject=We'd love to have you back!

	# email sender (make sure this user is allowed in Campaign Monitor)
	EmailSender=no_reply@mountainbuggy.com


The date range is used to control the 'window' of time that we look back for
dangling shopping carts. In the default settings above, we check for shopping
carts that are between 7 days and 1 day old.

Note that if the mail_logs table is being purged, the date range should always
be set such so that it does not look back into purged data. For
example, if the mail_logs is being purged every week, the date range should
never extend back further than seven days.

Testing the cronjob
--------------------

The following command may be used to test the cronjob (assuming the cronjob
is defined in the [CronjobPart-test] part. To determine this, check in
/extension/campaign_monitor/settings/cronjob.ini.append.php

    php runcronjobs.php -s mb_global test

**Be careful if you're using a database with real email addresses! Those users
will receive emails.**

Overriding the Mail template
----------------------------

The mail template is defined in

    design:shop/orderemail/html/abandoned_cart.tpl

The default mail template is just a boring placeholder. Feel free to override
it in the appropriate siteaccess. One parameter is passed in:

      order => (eZOrder)

To test the layout of the mail template, you can use a new view built for this
purpose:

http://dev.mountainbuggy.com/mb_global/campaign_monitor/preview_abandoned_cart_email/(OrderID)/2

Note that you may see nothing if there is no email template for the siteaccess
(in my case, I've added the campaign_monitor design to the mb_global siteaccess
[AdditionalSiteDesigns] list, which lets me load the default mail template.
You may or may not want to do that.)

Note that you'll need to grant permissions before you can see the view
mentioned above.

Indexing for speed
------------------

Optional, but **highly recommended**

Please consider running the /extension/campaign_monitor/sql/mysql/mysql.sql
file on the database server.

It adds an index to the mail_logs directory to speed up retrieval. Without it,
the cronjob will be extremely slow.
