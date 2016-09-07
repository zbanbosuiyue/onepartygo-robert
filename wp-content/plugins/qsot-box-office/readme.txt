=== OpenTickets - Box Office ===
Contributors: quadshot, loushou
Donate link: http://opentickets.com/
Tags: payments in admin, payments, box office, audit, roles, events, event management, tickets, ticket sales, ecommerce
Requires at least: 4.0
Tested up to: 4.3.x
Stable tag: master
Copyright: Copyright (C) 2009-2015 Quadshot Software LLC
License: OpenTickets Software License Agreement
License URI: http://opentickets.com/opentickets-enterprise-software-license-agreement/

A set of tools to allow a box office to sell tickets, take payments, keep track of accountability, and various other administrative tasks.

== Description ==

[OpenTickets – Box Office](http://opentickets.com/software/opentickets-virtual-box-office/ "OpenTickets - Box Office Software Description Page") turns your computer into a Box Office Terminal. You can sell tickets and take payments directly in the WP Admin. You can even put tickets on reserve, for those purchasers who will pay later. It also integrates with the [OpenTickets - Seating](http://opentickets.com/software/opentickets-seating/ "OpenTickets - Seating Software Description Page") extension, if you happen to have it also.

Additionally, it keeps an audit log of the various actions that take place over the course of an order's history, in a viewable audit log, which is displayed on the 'Edit Order' screen in the admin. That way you can keep track of what is happening on an order, and identify when things happened and who did them.

You are also provided with 3 new 'admin only' payment methods, which are only available to the admin users, so that you can classify the types of payments that a Box Office person may receive. These will help your bookkeeping process, by allowing you to designate what type of income came in and how much.

See how this extension can help you, by watching [some of our videos](http://opentickets.com/videos/ "Our videos page")

== Installation ==

= Instructional Videos =

If you are more of a 'just give me a video to show me how to do it' type person, then we have a few new videos that can help show you how to use OpenTickets - Box Office.

1. [OpenTickets - Box Office, Basic usage](https://www.youtube.com/watch?v=A-EIamKP4h8 "Video that demonstrates how to use the various features of this extension")

= Basic Installation =

These instructions are pretty universal, standard methods employed when you install any plugin. If you have ever installed one for your WordPress installation, you can probably skip this part.

The below instructions assume that you have:

1. Downloaded the OpenTickets - Box Office software from a link emailed to you, or from your [My Account page](http://opentickets.com/my-account/ "Visit your my-account page") on the OpenTickets website.
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
1. Start using OpenTickets - Box Office.

Via SSH:

1. FTP or SCP the file you downloaded from [OpenTickets.com](http://opentickets.com/) to your server. (We assume you know how to do this)
1. Login to your server via ssh. (.... you know this too).
1. Issue the command `cd /path/to/your/website/installation/wp-content/plugins/`, where /path/to/your/website/installation/wp-content/plugins/ is the plugins directory on your site.
1. `cp /path/to/qsot-box-office.zip .`, to copy the downloaded file to your plugins directory.
1. `unzip qsot-box-office.zip`, to unzip the downloaded file, creating an opentickets directory inside your plugins directory.
1. Open a browser, and follow steps 1-2 under 'Via the WordPress Admin' above, to get to your 'plugins' page.
1. Find OpenTickets - Box Office on your plugins list, click 'Activate' which is located directly below the name of the plugin.
1. Start using OpenTickets - Box Office

== Frequently Asked Questions ==

The FAQ's for OpenTickets - Box Office is currently located on [our website's FAQs Page](http://opentickets.com/faq).

== Changelog ==

= 2.0.3 - Feb/18/2016 =
* [new] now works with Stripe, and other javascript takeover based payment gateways
* [tweak] scrolls to the bottom of the payment box after each step of payment process

= 2.0.2 - Jan/25/2016 =
* [tweak] changed call to payment list in payment box to be compatible with the latest WC version

= 2.0.1 - Jan/21/2016 =
* [new] code changes to make compatible with qTranslate

= 2.0.0 - Dec/16/2015 =
* [tweak] code changes so that make this extension work with the new OTCE 2.0.0 template rules

= 0.9.8 =
* [tweak] adding an exceptions list to payment gateways allowed in admin

= 0.9.7 =
* [new] added readme.txt for plugin description

= 0.9.6 =
* [tweak] change gateway load order for new WC version

= 0.9.5 =
* [tweak] minor changes to allow Windows Server compatibility

= older-versions =
* older changelog entries omitted

== Upgrade Notice ==

= 0.9.7 =
Added the plugin description and readme.txt files; therefore, WordPress internals should report the description properly now.

== When should you use Box Office? ==

Box Office empowers you to create orders and take payments for them, on behalf of a customer. If you have a physical building where customers can go to obtain tickets to your event, or if you are accepting phone event registrations, then you probably want Box Office. Without it, taking payments from these types of customers becomes vastly more complicated.
