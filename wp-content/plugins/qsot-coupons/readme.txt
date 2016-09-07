=== OpenTickets - Coupons & Passes ===
Contributors: quadshot, loushou
Donate link: http://opentickets.com/
Tags: coupons, passes, buy one get one, bogo, season tickets, events, event management, tickets, ticket sales, ecommerce
Requires at least: 4.0
Tested up to: 4.3.x
Stable tag: master
Copyright: Copyright (C) 2009-2015 Quadshot Software LLC
License: OpenTickets Software License Agreement
License URI: http://opentickets.com/opentickets-enterprise-software-license-agreement/

Adds multiple abilities to limit the usage of coupons by event. Additionally adds the ability to 'sell coupons', so that flex passes and season passes are possible.

== Description ==

The [OpenTickets - Coupons & Passes](http://opentickets.com/software/opentickets-coupons/ "OpenTickets - Coupons & Passes Software Description Page") extension gives you the power to control how your coupons can be used for event tickets, and the power to issue Season Tickets and BOGO style passes. The core features include:

* Create products that issue a digital code, which can be used as a Season Ticket or BOGO style Pass
* Granularly control what events your coupons are good for, and how many usages per event they have
* Add coupons directly to orders in the admin, instead of manually calculating discounts

= Season Passes & BOGO style Passes =

This extension allows you create products, that when purchased, issue a digital code. That code can be used for a wide variety of purposes, some of which are Season Passes and Buy One Get One style Passes. For instance, if you owned a theater which produced 10 plays a year (each with 15 showings), you could create a Season Pass product, which would allow a user to choose one seat, on one showing, per each of the 10 events that year. Or you could offer a 'deal' where a family could buy a '4 tickets for the price of 3 tickets' code, which they could then use for any show throughout the year. Or you could create a 'two for one' deal for the first showing of the second of your 10 plays. The possibilities are endless.

= Coupon Control =

With this extension, you can now require that a coupon is only good for a certain event. You can also define that the coupon is good for any showing of that event (for instance any showing of one of your 10 plays from above). You can also define that a coupon is only good for 3 tickets for each event over the course of an entire year. You have a lot of control, so wrangle those coupons how you want them.

== Installation ==

= Instructional Videos =

If you are more of a 'just give me a video to show me how to do it' type person, then we have a few new videos that can help show you how to use OpenTickets - Coupons & Passes.

1. [OpenTickets - Coupons & Passes, Basic usage](https://www.youtube.com/watch?v=A-EIamKP4h8 "Video that demonstrates how to use the various features of this extension")

= Basic Installation =

These instructions are pretty universal, standard methods employed when you install any plugin. If you have ever installed one for your WordPress installation, you can probably skip this part.

The below instructions assume that you have:

1. Downloaded the OpenTickets - Coupons & Passes software from a link emailed to you, or from your [My Account page](http://opentickets.com/my-account/ "Visit your my-account page") on the OpenTickets website.
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
1. Start using OpenTickets - Coupons & Passes.

Via SSH:

1. FTP or SCP the file you downloaded from [OpenTickets.com](http://opentickets.com/) to your server. (We assume you know how to do this)
1. Login to your server via ssh. (.... you know this too).
1. Issue the command `cd /path/to/your/website/installation/wp-content/plugins/`, where /path/to/your/website/installation/wp-content/plugins/ is the plugins directory on your site.
1. `cp /path/to/qsot-coupons.zip .`, to copy the downloaded file to your plugins directory.
1. `unzip qsot-coupons.zip`, to unzip the downloaded file, creating an opentickets directory inside your plugins directory.
1. Open a browser, and follow steps 1-2 under 'Via the WordPress Admin' above, to get to your 'plugins' page.
1. Find OpenTickets - Coupons & Passes on your plugins list, click 'Activate' which is located directly below the name of the plugin.
1. Start using OpenTickets - Coupons & Passes

== Frequently Asked Questions ==

The FAQ's for OpenTickets - Coupons & Passes is currently located on [our website's FAQs Page](http://opentickets.com/faq).

== Changelog ==

= 2.0.5 - June/16/2016 =
* [fix] repaired auto calc-totals after adding coupon to order via the admin

= 2.0.4 - June/8/2016 =
* [fix] repaired add coupon flow for the admin edit order screen

= 2.0.2 - March/31/2016 =
* [tweak] updated admin 'add coupon to order' functionality for latest WC compatibility
* [fix] repaired 'purchased code' section of completed order email

= 2.0.1 - Jan/21/2016 =
* [new] code changes to be compatible with qTranslate

= 2.0.0 - Dec/16/2015 =
* [new] now compatible with OTCE 2.0.0
* [fix] repaired event search bug when setting up event limitation settings

= 0.9.6 - Dec/02/2015 =
* [fix] repaired the script security syntax

= 0.9.5 - Dec/01/2015 =
* [tweak] changed script security so that it is compatible with eAccelerator

= 0.9.4 =
* [new] added readme.txt for plugin description

= 0.9.3 =
* [tweak] minor changes to allow Windows Server compatibility

= 0.9.2 =
* [fix] fixed admin js bug dealing with adding coupons to order in the admin

= 0.9.1 =
* [tweak] using OTCE core modal instead of new modal system
* [remove] deleted obsolete code that was previously deprecated
* [fix] fixed some activation issues

= older-versions =
* older changelog entries omitted

== Upgrade Notice ==

= 0.9.4 =
Added the plugin description and readme.txt files; therefore, WordPress internals should report the description properly now.

== When should you use Coupons & Passes? ==

Coupons & Passes gives you a lot of new control over your WooCommerce coupon system. If you need to offer Season Passes, or if you find that your coupons need to be limited to specific shows, then you should consider using this plugin. Also, if you want to control how many times a specific coupon can be used for a specific event, this is a good one for you in that case also.
