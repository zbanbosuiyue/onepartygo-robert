=== OpenTickets - Display Options ===
Contributors: quadshot, loushou
Donate link: http://opentickets.com/
Tags: events in shop, shop, widget, shortcode, events, event management, tickets, ticket sales, ecommerce
Requires at least: 4.0
Tested up to: 4.3.x
Stable tag: master
Copyright: Copyright (C) 2009-2015 Quadshot Software LLC
License: OpenTickets Software License Agreement
License URI: http://opentickets.com/opentickets-enterprise-software-license-agreement/

Adds smart widgets and shortcodes for displaying the different elements of OpenTickets. Provides the ability to display events in the shop. Also provides different options for displaying your events on the frontend.

== Description ==

The [OpenTickets – Display Options](http://opentickets.com/product/display-options/ "OpenTickets – Display Options Software Description Page") Extension enables several new ways of displaying your Events on your WordPress website.

[There is a Display Options video to show how it works.](https://www.youtube.com/watch?v=D_O3FoI0Cw8 "Watch the video")

Once installed, there is a new ‘Settings’ tab, called ‘Display Options’, which controls the various features.

You can:

* allow events to show in the WooCommerce Shop experience, as if they were products
* use a shortcode to display upcoming events
* use a shortcode to display a featured event
* use a widget to display upcoming events
* use a widget to display a featured event

= Widgets =

Adding one of our widgets to your site is really easy. In fact, if you have ever added a widget, you already know how to do it. Simply goto your Admin, then Appearance -> Widgets. On that screen, draw and drop either the 'Upcoming Events' widget or the 'Featured Event' widget into one of your sidebar widget lists. Then update the widget options appropriately, like you would any other widget, and you are done!

= Shortcodes =

Adding a shortcode to your content is easy, because you do not have to learn or remember any shortcode syntax. Instead, when creating your content, you can simply click the 'Add Media' button, above the wysiwyg, like you would normally do to insert an image or gallery. In the modal that appears, on the left hand column, you will see a new nav item labeled 'Opentickets Shortcodes'. Selecting that option, will put the power of the shortcodes at your fingertips, and all without having to read some documentation to figure out how it works.

For your piece of mind, here are some examples of shortcodes enabled with this Extension.

[upcoming-events format=”list” limit=”10″ parent_id=”1″ after=”now” before=”next year” show_image=”1″ date_format=”m-d-Y @ h:ia” time_format=”h:ia” meta=”date,price,availability” columns=”title,date,price,availability”]

[featured-event format=”list” id=”1,2,3″ show_image=”1″ date_format=”m-d-Y @ h:ia” time_format=”h:ia” meta=”date,price,availability”]

== Installation ==

= Instructional Videos =

If you are more of a 'just give me a video to show me how to do it' type person, then we have a few new videos that can help show you how to use OpenTickets - Display Options.

1. [OpenTickets - Display Options, Basic usage](https://www.youtube.com/watch?v=D_O3FoI0Cw8 "Video that demonstrates how use the shortcodes and widgets that come with this extension")

= Basic Installation =

These instructions are pretty universal, standard methods employed when you install any plugin. If you have ever installed one for your WordPress installation, you can probably skip this part.

The below instructions assume that you have:

1. Downloaded the OpenTickets - Display Options software from a link emailed to you, or from your [My Account page](http://opentickets.com/my-account/ "Visit your my-account page") on the OpenTickets website.
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
1. Start using OpenTickets - Display Options.

Via SSH:

1. FTP or SCP the file you downloaded from [OpenTickets.com](http://opentickets.com/) to your server. (We assume you know how to do this)
1. Login to your server via ssh. (.... you know this too).
1. Issue the command `cd /path/to/your/website/installation/wp-content/plugins/`, where /path/to/your/website/installation/wp-content/plugins/ is the plugins directory on your site.
1. `cp /path/to/qsot-display-options.zip .`, to copy the downloaded file to your plugins directory.
1. `unzip qsot-display-options.zip`, to unzip the downloaded file, creating an opentickets directory inside your plugins directory.
1. Open a browser, and follow steps 1-2 under 'Via the WordPress Admin' above, to get to your 'plugins' page.
1. Find OpenTickets - Display Options on your plugins list, click 'Activate' which is located directly below the name of the plugin.
1. Start using OpenTickets - Display Options

== Frequently Asked Questions ==

The FAQ's for OpenTickets - Display Options is currently located on [our website's FAQs Page](http://opentickets.com/faq).

== Changelog ==

* 2.1.0 - June/16/2016 =
* [new] added filter to the upcoming events default shortcode args
* [new] added english translation template
* [tweak] timestamp overhaul, so that local time is displayed
* [tweak] made compatibile with pootlepress page builder
* [tweak] changed default sort order on event search
* [tweak] made it clear that the featured events event search was a search, not a browse, to cut down on confusion
* [fix] repaired visual issue with the find events box results list
* [fix] repaired issue with shop query that allows it to grab all events now
* [fix] repaired image to event association during event search for shortcodes

= 2.0.3 - Feb/12/2016 =
* [fix] error message display bug after plugin activation

= 2.0.2 - Jan/25/2016 =
* [tweak] update price range detection and display
* [tweak] added problematic event links to the 'update data' error message
* [fix] fixed 'update data' functionality

= 2.0.1 - Jan/21/2016 =
* [new] added qTranslate compatibility

= 2.0.0 - Dec/16/2015 =
* [fix] default settings now take effect when used with OTCE 2+

= 1.1.6 - Dec/02/2015 =
* [fix] repaired script security syntax

= 1.1.5 - Dev/01/2015 =
* [tweak] changed script security so that it is compatible with eAccelerator

= 1.1.4 - Nov/18/2015 =
* [tweak] fixing save on events that have EAs with large pricing matrixes

= 1.1.3 =
* [fix] repairing widget declarations to not throw PHP errors on older PHP version

= 1.1.2 =
* [new] added readme.txt for plugin description

= 1.1.1 =
* [fix] corrected query that adds the events to the shop page

= 1.1.0 =
* [fix] updated code to support new WC add to cart flow

= 1.0.3 =
* [fix] fixed ajax function for fetching cart fragments

= older-versions =
* older changelog entries omitted

== Upgrade Notice ==

= 1.1.2 =
Added the plugin description and readme.txt files; therefore, WordPress internals should report the description properly now.

== When should you use Display Options? ==

Display Options provides you additional ways of displaying your events. If you are bored with the calendar, or feel like it does not fit your needs, then it is highly likely that the Display Options plugin will give you what you need.
