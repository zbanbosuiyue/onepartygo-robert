=== OpenTickets - Advanced Reporting ===
Contributors: quadshot, loushou
Donate link: http://opentickets.com/
Tags: reports, reporting, anlytics, events, event management, tickets, ticket sales, ecommerce
Requires at least: 4.0
Tested up to: 4.4.x
Stable tag: master
Copyright: Copyright (C) 2009-2015 Quadshot Software LLC
License: OpenTickets Software License Agreement
License URI: http://opentickets.com/opentickets-enterprise-software-license-agreement/

Provides several additional reports to the OpenTickets Admin, which allow you to break down your tickets sales by various criteria

== Description ==

[OpenTickets - Advanced Reporting](http://opentickets.com/software/opentickets-advanced-reporting/ "View OpenTickets - Advanced Reporting Software Description Page") provides several additional, powerful reports that help you understand how your events are performing. These reports provide summaries of how many tickets were sold, how many discounts were given, overall show performance in numerical terms, and more. With these reports, at a glance you can determine which shows are making money, and which shows need some help. They also break down to which payment methods were used to pay, so that all your money can be accounted for at the end of the day.

* Break sales down by event, even child event
* See how many sales happened on a given day, or date range
* View sales for product/tickets in a given category

== Installation ==

= Instructional Videos =

Stay tuned. Instructional videos will be coming soon!

= Basic Installation =

These instructions are pretty universal, standard methods employed when you install any plugin. If you have ever installed one for your WordPress installation, you can probably skip this part.

The below instructions assume that you have:

1. Downloaded the OpenTickets - Advanced Reporting software from a link emailed to you, or from your [My Account page](http://opentickets.com/my-account/ "Visit your my-account page") on the OpenTickets website.
1. Have already installed WooCommerce and OpenTickets Community Edition, and set them up to your liking.
1. Have either some basic knowledge of the WordPress admin screen or some basic ftp and ssh knowledge.
1. The ability to follow an outlined list of instructions. ;-)

Via the WordPress Admin:

1. Login to the admin dashboard of your WordPress site.
1. Click the 'Plugins' menu item on the left sidebar, usually found somewhere near the bottom.
1. Near the top left of the loaded page, you will see an Icon, the word 'Plugins', and a button next to those, labeled 'Add New'. Click 'Add New'.
1. In the top left of this page, you will see another Icon and the words 'Install Plugins'. Directly below that are a few links, one of which is 'Upload'. Click 'Upload'.
1. On the loaded screen, below the links described in STEP #4, you will see a location to upload a file. Click the button to select the file you downloaded from [OpenTickets.com](http://opentickets.com/).
1. Once the file has been selected, click the 'Install Now' button.
    * Depending on your server setup, you may need to enter some FTP credentials, which you should have handy.
1. If all goes well, you will see a link that reads 'Activate Plugin'. Click it.
1. Once you see the 'Activated' confirmation, you will see new icons in the menu.
1. Start using OpenTickets - Advanced Reporting.

Via SSH:

1. FTP or SCP the file you downloaded from [OpenTickets.com](http://opentickets.com/) to your server. (We assume you know how to do this)
1. Login to your server via ssh. (.... you know this too).
1. Issue the command `cd /path/to/your/website/installation/wp-content/plugins/`, where /path/to/your/website/installation/wp-content/plugins/ is the plugins directory on your site.
1. `cp /path/to/qsot-reporting.zip .`, to copy the downloaded file to your plugins directory.
1. `unzip qsot-reporting.zip`, to unzip the downloaded file, creating an opentickets directory inside your plugins directory.
1. Open a browser, and follow steps 1-2 under 'Via the WordPress Admin' above, to get to your 'plugins' page.
1. Find OpenTickets - Advanced Reporting on your plugins list, click 'Activate' which is located directly below the name of the plugin.
1. Start using OpenTickets - Advanced Reporting

== Frequently Asked Questions ==

The FAQ's for OpenTickets - Advanced Reporting are currently located on [our website's FAQs Page](http://opentickets.com/faq).

== Changelog ==

= 1.0.1 - June/16/2016 =
* [tweak] narrowed output of by event report to only the events you choose
* [tweak] narrowed output of by date report to only the events you choose
* [tweak] re-enabling the 'all events' option in both reports
* [fix] repaired date selection on by event report
* [fix] repaired issue where report form was getting removed from the DOM in some cases

= 1.0.0 =
* [new] initial public release
* [tweak] changed csv report column names

= older-versions =
* older changelog entries omitted

== Upgrade Notice ==

= 1.0.0 =
Initial public release of Advanced Reporting.
