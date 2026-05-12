# WP Crontrol

Contributors: johnbillion, scompt
Tags: cron, wp-cron, crontrol, debug, woocommerce
Tested up to: 6.9
Stable tag: 1.21.0
License: GPL v2 or later
Donate link: https://github.com/sponsors/johnbillion

WP Crontrol enables you to take control of the cron events on your WordPress website or WooCommerce store.

## Description

WP Crontrol enables you to take control of the scheduled cron events on your WordPress website or WooCommerce store. From the admin screens you can:

 * View all scheduled cron events along with their arguments, schedule, callback functions, and when they are next due.
 * Edit, delete, pause, resume, and immediately run cron events.
 * Add new cron events.
 * Bulk delete cron events.
 * Add and remove custom cron schedules.
 * Export and download cron event lists as a CSV file.

WP Crontrol is aware of timezones, will alert you to events that have no actions or that have missed their schedule, and will show you a helpful warning message if it detects any problems with your cron system.

### Usage

1. Go to the `Tools → Cron Events` menu to manage cron events.
2. Go to the `Settings → Cron Schedules` menu to manage cron schedules.

### Documentation

[Extensive documentation on how to use WP Crontrol and how to get help for error messages that it shows is available on the WP Crontrol website](https://wp-crontrol.com/docs/how-to-use/).

### For site owners

Owners of WordPress websites and WooCommerce stores use WP Crontrol to ensure that scheduled cron events run correctly and efficiently. By providing complete control over cron events, WP Crontrol helps you:

* **Improve reliability**: Address missed or failed cron events, ensuring your website or WooCommerce store continues to function as expected.
* **Enhance security**: Monitor and control cron events to ensure automatic update checks are performed as they should.
* **Simplify management**: Add, edit, delete, and pause cron events from a user-friendly interface, without needing to write any code.
* **Gain insights**: Export cron event data for analysis or reporting.
* **Action Scheduler compatibility**: Full support for the Action Scheduler system in WooCommerce, which is used to process recurring payments, subscriptions, and background orders.
* **Clarity of times and timezones**: All times are shown with a clear and accurate indication of which timezone applies. No more guesswork!

### For developers

Developers use WP Crontrol to streamline and debug their WordPress development process:

* **Enhanced debugging**: Identify and troubleshoot issues with scheduled tasks, ensuring your scheduled events and their callbacks run as expected.
* **Custom schedules**: Create and manage custom cron schedules to fit the specific needs of your website, plugins, or themes, providing greater flexibility than just the core schedules.
* **Efficient workflow**: Add, edit, and delete cron events directly from the WordPress admin interface, saving time and reducing the need for manual coding.
* **Insightful monitoring**: Get insight into the performance and behavior of your scheduled tasks, allowing for optimization and better resource management.
* **Accurate debugging**: WP Crontrol goes to great lengths to ensure that running an event manually does so in a manner which exactly matches how WordPress core runs schdeuled events. This ensures that you can debug events accurately and with confidence.

### Other Plugins

I maintain several other plugins for developers. Check them out:

* [Query Monitor](https://wordpress.org/plugins/query-monitor/) is the developer tools panel for WordPress.
* [User Switching](https://wordpress.org/plugins/user-switching/) provides instant switching between user accounts in WordPress.

### Thanks

The time that I spend maintaining this plugin and others is in part sponsored by:

* [Automattic](https://automattic.com/)
* [ServMask](https://servmask.com/)
* [WP Staging](https://wp-staging.com/)
* [All my kind sponsors on GitHub](https://github.com/sponsors/johnbillion)

### Privacy Statement

WP Crontrol is private by default and always will be. It does not send data to any third party, nor does it include any third party resources. [WP Crontrol's full privacy statement can be found here](https://wp-crontrol.com/privacy/).

### Accessibility Statement

WP Crontrol aims to be fully accessible to all of its users. [WP Crontrol's full accessibility statement can be found here](https://wp-crontrol.com/accessibility/).

### Legal

Crontrol® is a registered trademark of Clever Things For The Web Limited.

## Frequently Asked Questions

### Does this plugin work with PHP 8?

Yes, it's actively tested and working up to PHP 8.4.

### I get the error "There was a problem spawning a call to the WP-Cron system on your site". How do I fix this?

[You can read all about problems spawning WP-Cron on the WP Crontrol website](https://wp-crontrol.com/help/problems-spawning-wp-cron/).

### Why do some cron events miss their schedule?

[You can read all about cron events that miss their schedule on the WP Crontrol website](https://wp-crontrol.com/help/missed-cron-events/).

### Why do some cron events reappear shortly after I delete them?

If the event is added by a plugin then the plugin most likely rescheduled the event as soon as it saw that the event was missing. To get around this you can instead use the "Pause this hook" action which means it'll remain in place but won't perform any action when it runs.

### Is it safe to delete cron events?

This depends entirely on the event. You can use your favourite search engine to search for the event name in order to find out which plugin it belongs to, and then decide whether or not to delete it.

If the event shows "None" as its action then it's usually safe to delete. Please see the other FAQs for more information about events with no action.

### Why can't I delete some cron events?

The WordPress core software uses cron events for some of its functionality and removing these events is not possible because WordPress would immediately reschedule them if you did delete them. For this reason, WP Crontrol doesn't let you delete these persistent events from WordPress core in the first place.

If you don't want these events to run, you can use the "Pause this hook" action instead.

### What happens when I pause an event?

Pausing an event will disable all actions attached to the event's hook. The event itself will remain in place and will run according to its schedule, but all actions attached to its hook will be disabled. This renders the event inoperative but keeps it scheduled so as to remain fully compatible with events which would otherwise get automatically rescheduled when they're missing.

As pausing an event actually pauses its hook, all events that use the same hook will be paused or resumed when pausing and resuming an event. This is much more useful and reliable than pausing individual events separately.

### What happens when I resume an event?

Resuming an event re-enables all actions attached to the event's hook. All events that use the same hook will be resumed.

### What does it mean when "None" is shown for the Action of a cron event?

This means the cron event is scheduled to run at the specified time but there is no corresponding functionality that will be triggered when the event runs, therefore the event is useless.

[You can read all about events with no action on the WP Crontrol website](https://wp-crontrol.com/help/no-action-cron-events/).

### How do I change the next run time or the schedule of a cron event?

You can change the time and schedule of a cron event by clicking the "Edit" link next to the event.

### Why do changes that I make to some cron events not get saved?

[You can read all about problems with editing cron events on the WP Crontrol website](https://wp-crontrol.com/help/problems-managing-events/).

### Can I export a list of cron events?

Yes, a CSV file of the event list can be exported and downloaded via the "Export" button on the cron event listing screen. This file can be opened in any spreadsheet application.

### Can I see a historical log of all the cron events that ran on my site?

Not yet, but I hope to add this functionality soon.

### Can I see a historical log of edits, additions, and deletions of cron events and schedules?

Yes. The excellent <a href="https://wordpress.org/plugins/simple-history/">Simple History plugin</a> has built-in support for logging actions performed via WP Crontrol.

### What's the use of adding new cron schedules?

Cron schedules are used by WordPress and plugins for scheduling events to be executed at regular intervals. Intervals must be provided by the WordPress core or a plugin in order to be used. As an example, many backup plugins provide support for periodic backups. In order to do a weekly backup, a weekly cron schedule must be entered into WP Crontrol first and then a backup plugin can take advantage of it as an interval.

### How do I create a new cron event?

There are two steps to getting a functioning cron event that executes regularly. The first step is telling WordPress about the hook. This is the part that WP Crontrol was created to provide. The second step is calling a function when your hook is executed.

*Step One: Adding the event*

From the Tools → Cron Events menu, click on Add Cron Event. Fill out the details of the event. You're best off using a hook name that conforms to normal PHP variable naming conventions. The schedule is how often the event will be executed. If you don't see a good interval, then add one in the Settings → Cron Schedules menu.

*Step Two: Writing the function*

This part takes place in PHP code (for example, in the `functions.php` file from your theme). To execute your hook, WordPress runs an action. For this reason, we need to tell WordPress which function to execute when this action is run. The following line accomplishes that:

~~~php
add_action( 'my_hookname', 'my_function' );
~~~

The next step is to write your function. Here's a simple example:

~~~php
function my_function() {
	wp_mail( 'hello@example.com', 'WP Crontrol', 'WP Crontrol rocks!' );
}
~~~

### How can I create a cron event that requests a URL?

From the Tools → Cron Events menu, click on Add Cron Event. Select the "URL cron event" option, fill out the details, and press the "Add Event" button.

[You can read all about the features and security of URL cron events on the WP Crontrol website](https://wp-crontrol.com/docs/url-cron-events/).

### How do I create a new PHP cron event?

From the Tools → Cron Events menu, click on Add Cron Event. Select the "PHP cron event" option and enter the schedule and next run time. The event schedule is how often your event will be executed. If you don't see a good interval, then add one in the Settings → Cron Schedules menu. In the "PHP Code" area, enter the PHP code that should be run when your cron event is executed. You don't need to provide the PHP opening tag (`<?php`).

Creating, editing, and running PHP cron events is subject to restrictive security permissions. [You can read all about the features and security of PHP cron events on the WP Crontrol website](https://wp-crontrol.com/docs/php-cron-events/).

### Which users can manage cron events and schedules?

Only users with the `manage_options` capability can manage cron events and schedules. By default, only Administrators have this capability.

### Which users can manage PHP cron events? Is this dangerous?

Only users with the `edit_files` capability can manage PHP cron events. This means if a user cannot edit files via the WordPress admin area (i.e. through the Plugin Editor or Theme Editor) then they also cannot add, edit, or delete a PHP cron event in WP Crontrol. By default only Administrators have this capability, and with Multisite enabled only Super Admins have this capability.

If file editing has been disabled via the `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` configuration constants then no user will have the `edit_files` capability, which means adding, editing, or deleting a PHP cron event will not be permitted.

Therefore, the user access level required to execute arbitrary PHP code does not change with WP Crontrol activated.

If the `CRONTROL_DISALLOW_PHP_EVENTS` constant is defined and set to `true`, then PHP cron events will be disabled completely. Any existing PHP cron events will remain in place (and can be deleted if user permissions allow) but their PHP code will not be executed when the event runs, and no PHP cron events can be added, edited, or run.

[You can read all about the features and security of PHP cron events on the WP Crontrol website](https://wp-crontrol.com/docs/php-cron-events/).

### Are any WP-CLI commands available?

The cron commands which were previously included in WP Crontrol are now part of WP-CLI itself. See `wp help cron` for more info.

### What happens when I deactivate the WP Crontrol plugin?

[You can read all about what happens when you deactivate the plugin on the WP Crontrol website](https://wp-crontrol.com/docs/deactivation/).

### How can I report a security bug?

You can submit a private security vulnerability report to WP Crontrol via [the Security tab on the GitHub repo](https://github.com/johnbillion/wp-crontrol/security). The GitHub Security Advisory process facilitates private collaboration on security issues. You'll receive credit for a valid report and a CVE if necessary.

Do not report security issues on the WordPress.org support forums or via email. Thank you.

### Who took the photo in the plugin header image?

The photo was taken by <a href="https://www.flickr.com/photos/michaelpardo/21453119315">Michael Pardo</a> and is in the public domain.

## Screenshots

1. Cron events can be modified, deleted, and executed
2. New cron events can be added
3. New cron schedules can be added, giving plugin developers more options when scheduling events
