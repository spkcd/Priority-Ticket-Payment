=== Priority Ticket Payment ===
Contributors: sparkwebstudio
Donate link: https://sparkwebstudio.com/
Tags: support, tickets, payment, woocommerce, elementor, awesome-support
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 1.2.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A comprehensive WordPress plugin for managing priority ticket submissions with payment integration for WooCommerce and Awesome Support.

== Description ==

Priority Ticket Payment is a powerful WordPress plugin designed to streamline priority support ticket management with integrated payment processing. Perfect for businesses offering premium support services, the plugin seamlessly integrates with WooCommerce for payment processing and Awesome Support for ticket management.

= 🚀 Key Features =

* **Multi-tier Priority System**: A (Premium), B (Standard), C (Free) ticket tiers
* **WooCommerce Integration**: Automatic payment processing and order management
* **Awesome Support Integration**: Seamless ticket creation with proper priority assignment
* **Elementor Pro Forms**: Direct integration with Elementor Pro form submissions
* **Agent Assignment**: Automatic assignment based on coach selection
* **File Attachments**: Support for file uploads (paid tiers only)
* **Auto-completion**: Orders automatically marked as completed after payment
* **Comprehensive Admin Interface**: Complete management of submissions and settings

= 🎯 Perfect For =

* Businesses offering premium support services
* Consultants providing tiered support levels
* Agencies managing client support requests
* Any organization needing paid priority support

= 🔧 Technical Features =

* Custom database table for submissions
* Comprehensive AJAX handling
* Security measures (nonces, capability checks, input sanitization)
* Automated cleanup of old submissions
* Extensive error logging and debugging
* Developer-friendly hooks and filters

= 💳 Payment Integration =

* Seamless WooCommerce integration
* Dynamic product creation for priority tiers
* Automatic order completion
* Payment status synchronization

= 🎫 Ticket Management =

* Automatic Awesome Support ticket creation
* Priority taxonomy assignment (a-ticket, b-ticket, c-ticket)
* Rich metadata for enhanced tracking
* Agent assignment based on form selections

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/priority-ticket-payment/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin settings under **Priority Tickets > Settings**
4. Set up your WooCommerce products for each priority tier
5. Configure Awesome Support priority terms (a-ticket, b-ticket, c-ticket)

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* WooCommerce (latest version recommended)
* Awesome Support (latest version recommended)
* Elementor Pro (for form integration)

== Frequently Asked Questions ==

= Do I need WooCommerce for this plugin to work? =

Yes, WooCommerce is required for payment processing of paid support tiers. The free tier can work without WooCommerce.

= Is Awesome Support required? =

While not absolutely required, Awesome Support is highly recommended for full ticket management functionality. Without it, tickets will be created as basic WordPress posts.

= Can I use this with other form builders? =

Currently, the plugin is specifically designed for Elementor Pro forms. Support for other form builders may be added in future versions.

= How do I configure the priority tiers? =

Priority tiers are configured in the plugin settings under **Priority Tickets > Settings**. You'll need to map your Elementor forms and set up corresponding WooCommerce products.

= What file types are supported for attachments? =

By default, the plugin supports PDF, DOC, DOCX, JPG, JPEG, PNG, and GIF files. This can be configured in the plugin settings.

== Screenshots ==

1. Admin submissions overview
2. Plugin settings interface
3. Priority tier configuration
4. Ticket creation interface
5. Order integration view

== Changelog ==

= 1.2.3 (2024-12-02) =
* Fixed: Eliminated duplicate file attachment display in tickets
* Fixed: Removed broken "4K 4K 4K" entries below proper download links
* Improved: Optimized logging for better performance and cleaner error logs
* Enhanced: Streamlined attachment processing workflow
* Maintained: Secure file storage and download functionality

= 1.2.2 (2024-01-XX) =
* CRITICAL FIX: Custom thank you page redirects now work reliably
* Fixed: Settings key mismatch that prevented custom thank you URLs from being read
* Enhanced: Multi-layer redirect system with PHP, template, and JavaScript fallbacks
* Enhanced: Upgraded to wp_safe_redirect() for better security
* Added: Loop prevention with transient-based tracking
* Added: Comprehensive debug script for troubleshooting
* Improved: Error logging for all redirect attempts

= 1.2.1 (2024-01-XX) =
* Fixed: "Reply & Close" button now properly sets ticket status to "Closed"
* Fixed: Email sending errors with proper HTML/text content payload
* Enhanced: Comprehensive Post SMTP and WordPress mail debugging
* Added: Duplicate prevention for email notifications
* Improved: Multiple fallback methods for email delivery
* Enhanced: Better error handling and logging

= 1.2.0 (2024-01-XX) =
* Added: Custom thank you page redirection after WooCommerce purchases
* Added: Enhanced subject column in admin submissions with tooltips
* Added: Improved file upload settings with tier-specific clarifications
* Added: Client name only ticket titles for cleaner appearance
* Added: Download attachment links in ticket body
* Added: German email notifications for ticket replies
* Fixed: Reply & Close status updates with comprehensive logging
* Enhanced: Immediate reply visibility with advanced cache management
* Improved: Removed order summary clutter from ticket interface

= 1.1.0 (2025-05-29) =
* Fixed: Awesome Support integration using correct wpas_insert_ticket function
* Fixed: Priority taxonomy assignment with proper term IDs (134, 135, 136)
* Fixed: Form data deserialization and PHP fatal error handling
* Fixed: Submission status updates on order completion
* Enhanced: Coach assignment with placeholder value filtering
* Enhanced: Priority display with proper tier mapping
* Enhanced: Comprehensive error handling and logging

= 1.0.0 (2025-05-28) =
* Initial release
* Multi-tier priority system implementation
* WooCommerce payment integration
* Awesome Support ticket creation
* Elementor Pro forms integration
* Complete admin interface
* File attachment support
* Security implementations

== Upgrade Notice ==

= 1.2.3 =
File attachment display fix: Eliminates duplicate attachments and broken "4K 4K 4K" entries. Performance optimization with reduced logging. Recommended update for cleaner ticket display.

= 1.2.2 =
CRITICAL FIX: Resolves custom thank you page redirect issues. Settings key mismatch fixed. Multi-layer redirect system ensures reliable custom thank you page functionality. Automatic migration for existing installations.

= 1.2.1 =
Major bug fixes for "Reply & Close" functionality and email notifications. Improved error handling and logging. Recommended update for all users.

= 1.2.0 =
Major feature update with custom thank you pages, enhanced admin interface, German email notifications, and improved ticket management. Recommended update.

= 1.1.0 =
Major fixes for Awesome Support integration and priority assignment. Ensure your Awesome Support priority terms have the correct IDs (134, 135, 136) before upgrading.

= 1.0.0 =
Initial release of Priority Ticket Payment plugin.

== Support ==

For support, feature requests, or bug reports, please visit:
* **Website**: [https://sparkwebstudio.com/](https://sparkwebstudio.com/)
* **Documentation**: Check the plugin's built-in help sections

== Developer Information ==

This plugin provides extensive hooks and filters for customization:

* Action hooks for submission processing
* Filter hooks for data modification
* Clean API for extending functionality
* Comprehensive inline documentation

For technical documentation, see the README.md file included with the plugin.

== Credits ==

Developed by **SPARKWEBStudio** - Premium WordPress Development
Website: [https://sparkwebstudio.com/](https://sparkwebstudio.com/) 