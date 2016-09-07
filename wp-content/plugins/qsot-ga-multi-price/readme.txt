=== OpenTickets - General Admission Multiple Pricing (GAMP) ===
Contributors: quadshot, loushou
Donate link: http://opentickets.com/
Tags: multiple prices, general admission, events, event management, tickets, ticket sales, ecommerce
Requires at least: 4.0
Tested up to: 4.3.x
Stable tag: master
Copyright: Copyright (C) 2009-2014 Quadshot Software LLC
License: OpenTickets Software License Agreement
License URI: http://opentickets.com/opentickets-enterprise-software-license-agreement/

Provides the ability to assign multiple General Admission prices to an Event Area.

== Description ==

[OpenTickets – General Admission Multiple Pricing (GAMP)](http://opentickets.com/software/opentickets-general-admission-multiple-pricing/ "OpenTickets - GAMP Software Desription Page") is an extension that allows you to select multiple prices for *General Admission Events*, eg: (Adult-$10/Child-$5/Senior-$7). This extension provides pricing flexibility, which empowers the admin user to define multiple pricing levels for an event, and provides the customer with a user-friendly experience to select from multiple pricing options in one quick checkout process.

GAMP also provides you the ability to setup multiple "pricing structures" for a single event area. This means that multiple events can share a single event area, and still have different pricing options available to them, without having to recreate the event area for each event. Even an event with multiple showings, where multiple pricing structures would be needed, are possible.

Eg: Day Show Tickets Price (Adult-$10/Child-$5/Senior-$7) / Night Show Ticket Price (Adult-$15/Child-$8/Senior-$10)

Features:
* Create General Admission Tier Ticket Pricing
* Offer customers the opportunity to purchase various tickets tiers in one checkout process
* Create Multiple General Admission Tier Ticket Pricing Structures for single and recurring Events
* Easily add pricing structures to Venue Event Area

== Installation ==

= Instructional Videos =

If you are more of a 'just give me a video to show me how to do it' type person, then we have a few new videos that can help show you how to use OpenTickets - GAMP.

1. [Multiple Pricing Options for your Event](https://www.youtube.com/watch?v=uCTDyM8hf4k "Video that demonstrates how to setup multiple pricing levels for your event")
1. [Same Event Area - Different prices on different showings](https://www.youtube.com/watch?v=NLyqnTXUIdY "Video that demonstrates how to setup different pricing for different showings, using the same event area")

= Basic Installation =

These instructions are pretty universal, standard methods employed when you install any plugin. If you have ever installed one for your WordPress installation, you can probably skip this part.

The below instructions assume that you have:

1. Downloaded the OpenTickets - GAMP software from a link emailed to you, or from your [My Account page](http://opentickets.com/my-account/ "Visit your my-account page") on the OpenTickets website.
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
1. Start using OpenTickets - GAMP.

Via SSH:

1. FTP or SCP the file you downloaded from [OpenTickets.com](http://opentickets.com/) to your server. (We assume you know how to do this)
1. Login to your server via ssh. (.... you know this too).
1. Issue the command `cd /path/to/your/website/installation/wp-content/plugins/`, where /path/to/your/website/installation/wp-content/plugins/ is the plugins directory on your site.
1. `cp /path/to/qsot-ga-multi-price.zip .`, to copy the downloaded file to your plugins directory.
1. `unzip qsot-ga-multi-price.zip`, to unzip the downloaded file, creating an opentickets directory inside your plugins directory.
1. Open a browser, and follow steps 1-2 under 'Via the WordPress Admin' above, to get to your 'plugins' page.
1. Find OpenTickets - General Admission Multiple Pricing (GAMP) on your plugins list, click 'Activate' which is located directly below the name of the plugin.
1. Start using OpenTickets - GAMP

== Frequently Asked Questions ==

The FAQ's for OpenTickets - GAMP is currently located on [our website's FAQs Page](http://opentickets.com/faq).

== Changelog ==

= 2.1.2 - June/16/2016 =
* [tweak] when adding tickets via the admin, the available event capacity now updates as tickets are chosen
* [fix] repaired issue where ticket prices were displayed twice in many locations

= 2.1.1 - Apr/14/2016 =
* [fix] fixed private ticket visibility so that only admins can see them now

= 2.1.0 - Feb/24/2016 =
* [new] all tickets attached to non-cancelled orders are considered 'confirmed' now
* [fix] fixed bug where admin generated orders could produce an overbooking scenario

= 2.0.6 - Feb/12/2016 =
* [tweak] updated code to use older syntax, for recent older PHP compatibility issues

= 2.0.5 - Feb/10/2016 =
* [tweak] changed pricing loader, to prevent cross contamination between gamp and seating

= 2.0.4 - Jan/25/2016 =
* [tweak] tweaked pricing structure output to be compatible with the output of the seating extension sister feature

= 2.0.3 - Jan/22/2016 =
* [new] german translation added

= 2.0.2 - Jan/21/2016 =
* [new] added qTranslate compatibility

= 2.0.1 - Dec/18/2015 =
* [fix] repaired activation error upon first activation

= 2.0.0 - Dec/16/2015 =
* [new] now GAMP compatible with the seating extension
* [new] all template for admin and frontend are now overridable in the theme
* [improvement] ajax security was significantly improved
* [improvement] improved all interface elements, and moved them all to overridable templates
* [improvement] improved performance of ticket selection process on frontend
* [improvement] improved admin ticket selection flow
* [improvement] improved performance of the pricing structure handler
* [fix] fixed edgecase extension activation bugs

= 1.3.1 =
* [new] added readme.txt file

= 1.3.0 =
* [remove] eliminated dependency on keychain

= 1.2.11 =
* [tweak] made PHP 5.2.x compatible

= 1.2.10 =
* [tweak] added purchase limit compatibility

= older-versions =
* older changelog entries omitted

== Upgrade Notice ==

= 1.3.1 =
Added the plugin description and readme.txt files; therefore, WordPress internals should report the description properly now.

== When should you use GAMP? ==

GAMP is meant as a solution that allows you to provide multiple pricing levels to a general admission event. An example of a situation that might require this would be if you need to provide a 'Child Price', an 'Adult Price' and a 'Senior Price'.

== What GAMP does not do ==

GAMP provides the ability to offer these multiple different pricing levels, but it *does not* allow you to control the quantity of each pricing option. Thus, if you needed to offer 30 VIP tickets and 300 General Admission tickets, GAMP does not cover this situation. For this situation, you may consider our [Seating Extension](http://stage.opentickets.com/software/opentickets-seating/), as it not only provides granular control of pricing options, but it also allows you to define how many are available.
