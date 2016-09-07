=== OpenTickets - Seating ===
Contributors: quadshot, loushou
Donate link: http://opentickets.com/
Tags: seating, assigned seats, zone pricing, multiple price, graphical, events, event management, tickets, ticket sales, ecommerce
Requires at least: 4.0
Tested up to: 4.3.x
Stable tag: master
Copyright: Copyright (C) 2009-2015 Quadshot Software LLC
License: OpenTickets Software License Agreement
License URI: http://opentickets.com/opentickets-enterprise-software-license-agreement/

Provides a graphical interface for creating and displaying an interactive seating chart. Also provides a high degree of control over ticket pricing choices and price availability.

== Description ==

[OpenTickets - Seating](http://opentickets.com/software/opentickets-seating/ "View OpenTickets - Seating Software Description Page") is a powerful tool that gives you a high degree of control over your entire event seat arrangement. You are given the power to not only control the layout of your event, but you also high granular control of the pricing of your various event sections. You are able to create completely seated events, zoned general admission events, and even a combination of the two. Some of the high-level features include:

* Create and Manage graphical seating charts for your events, which are cross browser compatible, even on modern mobile devices
* Use simple tools to draw your various seating chart elements, define their colors, control their prices, etc...
* Quickly and Easily number your seats, with a mass seat naming tool, instead of naming them one at a time
* Make your seating charts available on almost every modern mobile device!
* Create reserved seating arrangments, zoned ticket allotments, or any combination!

You can see how easy it is to create your seating arrangement by [watching some of our videos](http://opentickets.com/videos/ "Visit our video tutorials page")

== Installation ==

= Instructional Videos =

If you are more of a 'just give me a video to show me how to do it' type person, then we have a few new videos that can help show you how to use OpenTickets - Seating.

1. [OpenTickets - Seating, Basic tool overview](https://www.youtube.com/watch?v=Uzoox_x25gw "Video that describes the various drawing tools available to you")
1. [Drawing Seats](https://www.youtube.com/watch?v=dtSOHEpJUIs "Video that demonstrates how to actually draw seats and zones on your charts")
1. [Using the Mass Naming Tool](https://www.youtube.com/watch?v=v4LBhyuByk4 "Video that demonstrates how to quickly name all of your seats at once")
1. [Seat Pricing](https://www.youtube.com/watch?v=XbGMSbcS1HY "Video that shows you how to setup pricing for your seats")
1. [Zoom Zones](https://www.youtube.com/watch?v=K7R1C-JNHLk "Video which shows how to make and use a Zoom Zone")
1. [Use the chart for an event](https://www.youtube.com/watch?v=wDbMSi_hFlo "Video that demonstrates how to use the seating chart for your event")

= Basic Installation =

These instructions are pretty universal, standard methods employed when you install any plugin. If you have ever installed one for your WordPress installation, you can probably skip this part.

The below instructions assume that you have:

1. Downloaded the OpenTickets - Seating software from a link emailed to you, or from your [My Account page](http://opentickets.com/my-account/ "Visit your my-account page") on the OpenTickets website.
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
1. Start using OpenTickets - Seating.

Via SSH:

1. FTP or SCP the file you downloaded from [OpenTickets.com](http://opentickets.com/) to your server. (We assume you know how to do this)
1. Login to your server via ssh. (.... you know this too).
1. Issue the command `cd /path/to/your/website/installation/wp-content/plugins/`, where /path/to/your/website/installation/wp-content/plugins/ is the plugins directory on your site.
1. `cp /path/to/qsot-seating.zip .`, to copy the downloaded file to your plugins directory.
1. `unzip qsot-seating.zip`, to unzip the downloaded file, creating an opentickets directory inside your plugins directory.
1. Open a browser, and follow steps 1-2 under 'Via the WordPress Admin' above, to get to your 'plugins' page.
1. Find OpenTickets - Seating on your plugins list, click 'Activate' which is located directly below the name of the plugin.
1. Start using OpenTickets - Seating

== Frequently Asked Questions ==

The FAQ's for OpenTickets - Seating is currently located on [our website's FAQs Page](http://opentickets.com/faq).

== Changelog ==

= 2.3.0 - Aug/15/2016 =
* [new] added support for the Table Service plugin
* [new] added hooks for certain manipulating certain parts of the UI
* [fix] couple bugs with drawing tool in seating chart creation flow

= 2.2.0 - June/16/2016 =
* [new] added private ticket compatibility to seated events
* [translations] updated language files with new strings
* [tweak] changed style of 'proceed to cart' button, so that it matches community equivalent
* [tweak] when adding seats to an order via the admin, the remaining tickets count now updates as seats are selected
* [fix] repaired overlay bug in ticket selection tool, where sometimes the overlay would not get removed
* [fix] repaired a bug where seat-customized pricing was not working in some cases
* [fix] repaired edge case where in a user could overbook an event
* [fix] repaired edge case where the admin javascript could produce and infinite loop
* [fix] minor code tweaks to prevent php warnings in some cases

= 2.1.5 - March/29/2016 =
* [improvement] better error messages on denied reservations
* [improvement] added ability to have private ticket types
* [fix] repaired purchase limit logic on seated events
* [fix] repaired 'remove customized pricing' flow in edit event page
* [fix] repaired glitch in ticket selection ui overlay when using one click ticket selection

= 2.1.4 - March/23/2016 =
* [tweak] updated mouse location detection so that drawing and selection are directly under the mouse pointer now
* [fix] repaired copy paste bug (with ctrl/cmd-c and ctrl/cmd-v) where new zones were deleted upon chart save
* [fix] changed logic to clear all seating chart cache upon save. was causing a problem when memcache plugin was used

= 2.1.3 - March/10/2016 =
* [fix] corrected seating chart editor color selection box position
* [fix] fixed pattern naming bug with 'tall' zones

= 2.1.2 - Mar/9/2016 =
* [tweak] added back button to new tools
* [fix] corrected wording on new tools

= 2.1.1 - Mar/8/2016 =
* [new] two new tools to help with older version migrations
* [tweak] updated resync tool for seated events
* [tweak] changed ticket selection logic to choose proper template
* [fix] corrected template button label

= 2.1.0 - Feb/25/2016 =
* [new] all tickets attached to non-cancelled orders are considered 'confirmed' now
* [new] order cancellation message includes all information about tickets that were cancelled for historical value
* [new] new admin setting for the time length of the 'interest' phase of ticket selection
* [tweak] adjusted output of new tool
* [tweak] adjusted the reported tickets statuses on the attendee report
* [fix] corrected an edgecase overbooking bug that could happen during admin order creation
* [fix] corrected the admin ticket selection modal blocking bug
* [fix] corrected a template warning in the seating chart output

= 2.0.14 - Feb/18/2016 =
* [new] added tool to clear out seating and pricing cached data
* [fix] repaired custom pricing save and load bugs

= 2.0.13 - Feb/16/2016 =
* [tweak] re-added the one-click option for seated events
* [fix] repaired new pricing save bug

= 2.0.12 - Feb/12/2016 =
* [new] added Seat/Zone column to attendee report

= 2.0.11 - Feb/10/2016 =
* [fix] resolved issue where zoom buttons and sometimes entire chart were not rendered
* [tweak] changed how memcache is leveraged when available, for pricing lookups

= 2.0.10 - Jan/25/2016 =
* [fix] resolved edgecase bug where selecting multiple zone seats, then selecting a single seated seat caused a reservation error

= 2.0.9 - Jan/13/2016 =
* [fix] corrected an edgecase bug that in some cases allowed booking of more tickets than were available

= 2.0.8 - Jan/08/2016 =
* [tweak] updated the import export process to work with 2.0.x structure

= 2.0.7 - Jan/06/2016 =
* [fix] repaired system-status resync tool when used with seating
* [fix] repaired checkin process for seated events

= 2.0.6 - Dec/31/2015 =
* [new] added code to handle seated events on advanced tools

= 2.0.5 - Dec/22/2015 =
* [tweak] changed logic so that capacity 1 zones do not ask how many
* [fix] repaired reserve quantity more than 1 reservation / cart bug
* [fix] repaired ticket selection UI update upon reservation removal

= 2.0.4 - Dec/21/2015 =
* [fix] repaired ticket selection error

= 2.0.3 - Dec/21/2015 =
* [new] added several new filters for modifying zone lookups and such
* [tweak] several tweaks added so that invite a friend will work
* [fix] remove much of the js debug
* [fix] added the frontend template for displaying status messages

= 2.0.2 - Dec/18/2015 =
* [fix] repaired zoom icon alignment when custom icons are not used

= 2.0.1 - Dec/18/2015 =
* [new] added setting to control position of seating chart, relative to seat selection form
* [new] added settings to control zoom icons on the fronend

= 2.0.0 - Dec/16/2015 =
* [new] seating now compatible with the GAMP extension
* [new] all admin and frontend templates are now overridable in the theme
* [improvement] seating ticket selection interface now respects button text customization settings
* [improvement] adjusted default settings box position for the drawing interface, so that you can still hit the save button without moving the box
* [improvement] ajax security vastly improved
* [fix] repaired order item / cart item displays to show seat selected
* [fix] fixed seating chart image ssl issues
* [fix] fixed marquee tool drawing bug

= 1.2.18 - Nov/24/2015 =
* [new] mobile now supports touch based zooming and panning
* [improvement] added advanced caching that speeds up loading the seating and pricing in frontend and backend
* [tweak] doubled zoom in and zoom out range
* [fix] repaired non-js seat selection
* [fix] repaired activation order bug
* [remove] seating information no longer loads via ajax. it loads with the page

= 1.2.17 - Nov/16/2015 =
* [new] ability to remove pricing structures from seating charts
* [improvement] major save speed improvement for seating charts
* [improvement] overall performance mods for frontend performance
* [tweak] long lists of selected zones are truncated in the customized pricing screen
* [tweak] corrected calendar capacity display on seated events
* [fix] fixed issue where large seating charts could cause calendar to not load
* [fix] fixed customized pricing so that now it shows in the admin seat selection screens

= 1.2.16 - Nov/13/2015 =
* [tweak] code change to cover the edge case of a missing ticket for an event, which caused an error previously

= 1.2.15 - Nov/12/2015 =
* [improvement] sped up pricing structure fetching
* [improvement] added caching the pricing structure logic for repeat fetches
* [tweak] changes to be compatible with woocommerce-cart-addons plugin

= 1.2.14 - Nov/10/2015 =
* [tweak] changed logic to handle memcache object cache responses

= 1.2.13 =
* [tweak] changed pricing option verbiage to be more concise

= 1.2.12 =
* [improvement] another seating chart save speed improvement
* [improvement] adding 'loading' overlay to seating image during pre-load
* [fix] repairing 'hide on frontend' admin setting for non-circle shapes

= 1.2.11 =
* [improvement] now internally caching zone information. improves page load performance
* [improvement] loads only the core GA UI or seating UI, depending on type of event area. improves performance and reduces page load
* [improvement] deferring all seating UI loading until after the page loads. big performance boost
* [fix] repaired 'hide on frontend' admin tool and frontend functionality
* [fix] repaired legacy GA events UI when seating is active

= 1.2.10 =
* [tweak] zone save is now in smaller chunks for low powered dbs
* [fix] fixed bug with 'hide on frontend' zone option setting

= 1.2.9 =
* [new] added readme.txt for plugin description
* [tweak] now accounting for weird payment situations

= 1.2.8 =
* [fix] resolved bug where customer_ids were being set to 0 when admin users selected the seats

= 1.2.7 =
* [tweak] code changes needed to integrate with the new seating report in OTCE

= 1.2.6 =
* [tweak] integrated with the new advanced tools in OTCE

= 1.2.5 =
* [new] WC feature "order again" now being handled
* [fix] cart sync issues resolved

= older-versions =
* older changelog entries omitted

== Upgrade Notice ==

= 1.2.9 =
Added the plugin description and readme.txt files; therefore, WordPress internals should report the description properly now.

== When should you use Seating? ==

Seating is an extremely powerful tool. If you need to have granular control over the levels of pricing a ticket can have, or if you need to specify how many tickets of a given price level are available, you may want to use this extension. If you have reserved seating in your venue, or a combination of reserved seating and zone seating, then definitely need this extension. If you want an interactive floor plan for your end users, this is the extension for you.
