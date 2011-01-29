=== Plugin Name ===
Contributors: mitchoyoshitaka, automattic
Plugin Name: ShrimpTest
Tags: shrimptest, abtesting, testing, metrics
Requires at least: 3.0
Tested up to: 3.1
Stable tag: 1.0b3

A/B testing, the WordPress way.

== Description ==

ShrimpTest is an A/B testing solution for WordPress under development at Automattic. For an introduction to this project, I invite you to read [Every blog has a purpose](http://shrimptest.wordpress.com/2010/06/01/every-website-has-a-purpose/).

This version (1.0b3) is a release candidate. [Bug reports appreciated.](http://plugins.trac.wordpress.org/newticket?component=shrimptest)

== Installation ==

1. Upload `shrimptest.zip` to your plugins directory ( usally `/wp-content/plugins/` )
2. Unzip the `shrimptest.zip` file
3. Activate the plugin through the 'Plugins' menu in WordPress

= Caching support (WP Super Cache and W3 Total Cache) =

*If you are using WP Super Cache,* move the `shrimptest-cache-plugin.php` file to the `plugins/wp-super-cache/plugins/` and make sure your WP Super Cache is in Half On mode. ShrimpTest support for WP Super Cache does not work if WP Super Cache is in Full On mode.

*If you are using W3 Total Cache,* move the `shrimptest-cache-plugin.php` file to the `plugins/w3-total-cache/plugins/` and make sure W3 Total Cache's Page Cache is using the "Disk (basic)" method. ShrimpTest support will not work with the "Disk (enhanced)" method.

If you use any caching plugin without the ShrimpTest support, *your experiment results will be invalid.*

== Frequently Asked Question ==

= Question =

Answer.

== Changelog ==

= 0.1 =
* July 5, 2010 beta
= 0.2 =
* late July beta
= 1.0b1 =
* late August beta
= 1.0b2 =
* updated for WordPress 3.1 compatibility
	* takes advantage of new Admin Bar functionality
* some minor bug fixes
= 1.0b3 =
* some security patches (thanks Mark Jaquith!)
* minor bug fixes

== Upgrade Notice ==

