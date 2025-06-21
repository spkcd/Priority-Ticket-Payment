# Priority Ticket Payment WordPress Plugin

**Version:** 1.2.7  
**Author:** SPARKWEBStudio  
**License:** GPL v2 or later

A comprehensive WordPress plugin for managing priority ticket submissions with payment integration for WooCommerce and Awesome Support. Features Elementor Pro form integration, automatic ticket creation, priority assignment, and professional agent management.

## 🎯 Key Features

### 💳 **WooCommerce Integration**
- **Custom Thank You Pages**: Redirect customers to custom pages after ticket purchases
- **Automatic Order Processing**: Seamless integration with WooCommerce checkout
- **Priority Tier Detection**: Intelligent user priority assignment based on purchase history
- **Product Auto-Creation**: Automatic WooCommerce product generation for each tier

### 🎫 **Awesome Support Integration**
- **Automatic Ticket Creation**: Creates support tickets from completed orders
- **Priority Assignment**: Maps payment tiers to Awesome Support priority levels
- **Agent Assignment**: Automatic coach/agent assignment based on form selections
- **Clean Ticket Interface**: Professional, clutter-free ticket display

### 📝 **Elementor Pro Forms**
- **Dynamic Form Selection**: Different forms for each priority tier (A, B, C, D)
- **Multiple Form IDs**: Support unlimited additional form IDs across different pages
- **Smart Field Detection**: Automatic subject, message, and attachment handling
- **Auto-Population**: Name and email fields automatically filled for logged-in users
- **File Upload Support**: Up to 3 file attachments for paid tiers
- **Real-time Validation**: Instant feedback and validation

### 💌 **Email Notifications**
- **German Client Notifications**: Professional email templates in German
- **Custom Branding**: UMGANG UND SORGERECHT branded communications
- **Instant Notifications**: Real-time email alerts for ticket replies
- **Smart Link Generation**: Direct links to ticket overviews

### ⚡ **Performance & Caching**
- **Immediate Visibility**: Client replies visible instantly without logout/login
- **Advanced Cache Management**: Support for Redis, Memcached, and persistent caches
- **Smart Cache Clearing**: Targeted cache invalidation for optimal performance
- **No Refresh Required**: Seamless user experience

## 🚀 What's New in Version 1.2.6

### 🛠️ **Auto-Population Fix**
- **Universal Coverage**: Auto-population now works with ALL Elementor forms (not just shortcode forms)
- **Smart Detection**: Only activates on pages with configured priority ticket forms
- **Dynamic Support**: Handles popup forms, AJAX-loaded forms, and dynamic content
- **Performance Optimized**: Efficient loading only for logged-in users with configured forms

### 🆕 **Previous: Multiple Form IDs Support (v1.2.5)**
- **Additional Form IDs**: Support unlimited Elementor form IDs across different pages
- **Comma-Separated Management**: Easy setup with "f755476, form123, another-form" format
- **Shortcode Enhancement**: Use `[priority_ticket_form form_id="f755476"]` for specific forms
- **Centralized Settings**: Manage all form IDs from single admin panel
- **Auto-Detection**: All forms automatically work with priority ticket system

### 🎯 **Previous Features (v1.2.4)**
- **Smart Form Filling**: Name and email fields automatically populated when users are logged in
- **Time-Saving UX**: Eliminates repetitive data entry for returning customers
- **Multiple Detection Methods**: Works with various Elementor form field configurations
- **Non-Intrusive**: Only fills empty fields, preserves user-entered data
- **Universal Compatibility**: Functions across different browsers and devices

---

## 🚀 Previous Updates

### ✅ **File Attachments Fixed (v1.2.3)**
- **Eliminated Duplicate Attachments**: Fixed issue where files appeared twice in tickets (once as proper links, once as broken "4K 4K 4K" entries)
- **Clean Display**: Attachments now appear only once as numbered download links
- **No Functionality Loss**: Files remain securely stored and accessible

### ⚡ **Performance Optimized (v1.2.3)**
- **Reduced Logging**: Streamlined debug output for better performance
- **Cleaner Error Logs**: Removed excessive form submission debugging
- **Faster Processing**: Optimized attachment handling workflow

---

## 🚀 What's New in Version 1.2.2

### 🛠️ **CRITICAL FIX: Custom Thank You Page Redirects (v1.2.2)**
- **Fixed Settings Key Mismatch**: Resolved critical bug where custom thank you page URLs weren't being read
- **Multi-Layer Redirect System**: Implemented robust redirect system with three fallback methods
- **Enhanced Security**: Upgraded to `wp_safe_redirect()` with proper status codes
- **Loop Prevention**: Added transient-based tracking to prevent redirect loops
- **Debug Tools**: Added comprehensive diagnostic script for troubleshooting
- **Better Logging**: Enhanced error logging for all redirect attempts

### 🐛 **Previous Bug Fixes (v1.2.1)**
- **Fixed "Reply & Close" Button**: Now properly sets ticket status to "Closed" (ID=3) and persists across reloads
- **Fixed Email Sending Errors**: Resolved "missing_parameter" error with proper HTML/text content payload
- **Enhanced Logging**: Added comprehensive Post SMTP and WordPress mail debugging
- **Duplicate Prevention**: Prevents multiple email notifications for same reply
- **Improved Error Handling**: Multiple fallback methods for email delivery

## 🎉 Major Features Added in Version 1.2.0

### ✨ **Major Features Added**
- **Custom Thank You Page Redirection** after WooCommerce purchases
- **Enhanced Subject Column** in admin submissions with tooltips
- **Improved File Upload Settings** with tier-specific clarifications
- **Client Name Only Ticket Titles** for cleaner appearance
- **Download Attachment Links** in ticket body with professional formatting
- **Removed Order Summary Clutter** from ticket interface
- **Fixed Reply & Close Status Updates** with comprehensive logging
- **German Email Notifications** for ticket replies
- **Immediate Reply Visibility** with advanced cache management

### 🛠️ **Technical Improvements**
- Advanced caching system with Redis/Memcached support
- Comprehensive logging and debugging features
- Enhanced security with proper sanitization
- Better error handling and validation
- Extensible hook system for developers

## 📋 System Requirements

- **WordPress:** 5.0 or higher
- **PHP:** 7.4 or higher
- **WooCommerce:** Latest version (recommended)
- **Awesome Support:** Latest version (recommended)
- **Elementor Pro:** Required for form functionality

## 🎛️ Priority Tier System

| Tier | Price | Description | Features |
|------|-------|-------------|----------|
| **A** | 100€ | Premium | Full features, file uploads, priority support |
| **B** | 50€ | Standard | Full features, file uploads, standard support |
| **C** | 100€ | Basic | Limited features, coaching product buyers |
| **D** | 0€ | Free | Basic support, character limits, no uploads |

## ⚙️ Configuration

### 1. **Plugin Integrations**
Enable required integrations in the admin settings:
- ✅ Elementor Pro Integration
- ✅ WooCommerce Integration  
- ✅ Awesome Support Integration

### 2. **Form Configuration**
Set up Elementor form IDs for each tier:
- Premium Form ID (100€)
- Standard Form ID (50€)  
- Basic Form ID (100€)
- Free Form ID (0€)
- **Additional Form IDs**: Comma-separated list for extra forms (e.g., "f755476, form123, another-form")

### 3. **Payment Settings**
- **Custom Thank You Page URL**: Set redirect destination after purchase
- **Product IDs**: Link WooCommerce products to each tier
- **Coaching Product IDs**: Special pricing for coaching customers

### 4. **System Settings**
- **Max File Size**: Upload limit (1-50 MB)
- **Max Attachments**: Number of files allowed (1-5)
- **Auto Cleanup**: Automatic cleanup of old pending submissions

## 📧 Email Templates

### Client Notification (German)
```
Subject: Neue Antwort auf Ihr Coaching-Ticket

Liebe Klientin,

Sie haben eine Antwort auf Ihr Coaching Ticket erhalten. 
[Ticket Übersicht ansehen] (styled button)

Das Ticket bleibt geöffnet für weitere Fragen.

Beste Grüße
Ihre Coachin von
UMGANG UND SORGERECHT
```

## 🔧 Developer Hooks

### Actions
- `priority_ticket_payment_refresh_display` - Trigger display refresh
- `wpas_after_add_reply` - After reply addition
- `wpas_ticket_status_updated` - Status change monitoring

### Filters
- `priority_ticket_payment_tier_detection` - Customize tier logic
- `priority_ticket_payment_email_template` - Modify email content

## 🗃️ Database Structure

### Submissions Table
- `id` - Unique submission ID
- `user_id` - WordPress user ID
- `form_data` - Serialized form submission data
- `attachments` - File attachment information
- `price` - Calculated tier price
- `payment_status` - Current payment status
- `order_id` - WooCommerce order ID
- `token` - Unique security token
- `awesome_support_ticket_id` - Linked ticket ID
- `created_at` - Submission timestamp

## 🛡️ Security Features

- **Nonce Verification**: All AJAX operations protected
- **Input Sanitization**: `sanitize_text_field()`, `esc_url_raw()`
- **File Upload Security**: Type and size validation
- **Directory Protection**: `.htaccess` security rules
- **Permission Checks**: Proper capability validation

## 📊 Admin Features

### Submissions Management
- **List View**: All submissions with filtering
- **Status Management**: Update payment status
- **Manual Ticket Creation**: Create tickets from completed orders
- **Bulk Operations**: Delete multiple submissions
- **Export/Import**: Data management tools

### Statistics & Cleanup
- **Cleanup Stats**: Monitor old pending submissions
- **File Management**: Track upload directory usage
- **Manual Cleanup**: Force cleanup operations
- **Automated Cleanup**: Scheduled maintenance

## 🚨 Troubleshooting

### Common Issues

**Replies Not Visible Immediately:**
- Check cache settings in hosting panel
- Verify object cache configuration
- Review error logs for cache clearing issues

**Email Notifications Not Sending:**
- Verify SMTP settings
- Check spam/junk folders
- Review WordPress email logs

**File Uploads Failing:**
- Check PHP upload limits
- Verify directory permissions
- Review file type restrictions

**Custom Thank You Page Not Working:**
- Go to WooCommerce > Settings > Products > Priority Ticket Payment
- Ensure "Enable WooCommerce Integration" is checked
- Set a valid custom thank you page URL (e.g., `https://umgang-und-sorgerecht.com/thank-you-ticket/`)
- Use the debug script (`debug-thank-you-settings.php`) to check configuration
- Check error logs for "Priority Ticket Payment" redirect messages

### Debug Logging
Enable WordPress debug logging to monitor plugin operations:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

### Debug Tools
For custom thank you page issues, upload `debug-thank-you-settings.php` to your WordPress root and visit as admin to check all settings. **Remember to delete after use!**

## 📞 Support

For technical support and customization:

**SPARKWEBStudio**
- Website: https://sparkwebstudio.com/
- Email: support@sparkwebstudio.com

### Documentation
- Plugin documentation available in `/docs` folder
- Inline code comments for developers
- Hook reference in developer guide

## 📄 License

This plugin is licensed under the GPL v2 or later.

```
Copyright (C) 2024 SPARKWEBStudio

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
```

## 🏗️ Development

### Contributing
1. Fork the repository
2. Create feature branch
3. Commit changes
4. Submit pull request

### Coding Standards
- WordPress Coding Standards
- PSR-4 Autoloading
- Comprehensive documentation
- Unit testing (PHPUnit)

---

**Last Updated:** January 2024  
**Tested up to:** WordPress 6.4  
**Stable Tag:** 1.2.2 